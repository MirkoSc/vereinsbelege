<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\ArtifactKind;
use App\Domain\AuditAction;
use App\Domain\BlobMeta;
use App\Domain\DocumentSource;
use App\Domain\DocumentStatus;
use App\Domain\JobExecutor;
use App\Domain\OcrStatus;
use App\Domain\SupplierKeyKind;
use App\Domain\SupplierOrigin;
use App\Domain\SupplierRole;
use App\Domain\SystemRole;
use App\Repository\AiProviderRepository;
use App\Repository\AuditLogRepository;
use App\Repository\BlobRepository;
use App\Repository\CategoryRepository;
use App\Repository\DocumentArtifactRepository;
use App\Repository\DocumentRepository;
use App\Repository\InvoiceRepository;
use App\Repository\JobRepository;
use App\Repository\RoleRepository;
use App\Repository\SupplierRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Repository\VaultRepository;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Document\KiAuslesung;
use App\Service\Document\LieferantenAufloesung;
use App\Service\Job\JobLaufStatus;
use App\Service\Job\JobRunner;
use App\Service\Ki\KiFaehigkeiten;
use App\Service\Ki\LlmAntwort;
use App\Service\MasterData\Aufloesung;
use App\Service\MasterData\AufloesungsArt;
use App\Service\MasterData\SupplierService;
use App\Service\MasterData\SupplierZusammenfuehrung;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Service\Upload\MagicBytes;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FakeChatHttp;
use App\Tests\Support\FakeJpeg;
use App\Tests\Support\FakeLlmClient;
use App\Tests\Support\KiAuslesungFabrik;

/**
 * The supplier resolution (issue #48/M7-6, docs/spec/03-erfassung-und-ki.md
 * section 7) through the real job runner: `ai_extract` reads a receipt with
 * a recorded answer (no real AI, CLAUDE.md section 6), queues
 * `resolve_supplier`, which finds the supplier in all six steps - VAT id
 * or tax number, IBAN, creditor id, normalised name, similar name (only a
 * suggestion), new supplier with `needs_review` - and stores how, encrypted.
 * Also the blind-index preselection the prompt carries. Synthetic data only.
 */
final class LieferantenAufloesungJobTest extends DatabaseTestCase
{
    private const string KEY = 'sk-test-standard-0000-nicht-echt';

    private const string IBAN = 'DE89370400440532013000';

    private const string USTID = 'DE123456789';

    private const string GLAEUBIGER = 'DE98ZZZ09999999999';

    private Vault $tresor;
    private int $userId;
    private string $blobDir;
    private ServerCrypto $crypto;
    private AuditLog $audit;
    private FakeLlmClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        $this->blobDir = sys_get_temp_dir() . '/vb_lieferanten_blobs_' . uniqid('', true);
        mkdir($this->blobDir, 0775, true);

        $this->crypto = new ServerCrypto((string) base64_decode(self::configData()['server_key'], true));
        $this->tresor = Vault::create();
        new VaultRepository($this->pdo())->insert($this->tresor->publicKey());
        $this->audit = new AuditLog(new AuditLogRepository($this->pdo()), new VaultRepository($this->pdo()), $this->crypto);

        $this->userId = new UserRepository($this->pdo())->insert(
            $this->crypto->encrypt('finanzen@example.org'),
            random_bytes(32),
            $this->crypto->encrypt('Fritz Finanzen'),
            'hash',
            mfaRequired: false,
        );
        $rollen = new RoleRepository($this->pdo());
        $finanzen = $rollen->findSystem(SystemRole::Finanzen)?->id;
        assert($finanzen !== null);
        $rollen->assignToUser($this->userId, [$finanzen]);

        $anbieter = new AiProviderRepository($this->pdo(), $this->crypto);
        $profil = $anbieter->standard();
        assert($profil !== null);
        $anbieter->update($profil->id, $profil->name, $profil->baseUrl, $profil->model, new KiFaehigkeiten(true, true, 2, 4000), $profil->timeoutS, true, self::KEY);
        $this->client = new FakeLlmClient([]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->blobDir . '/*/*') ?: [] as $datei) {
            unlink($datei);
        }
        foreach (glob($this->blobDir . '/*') ?: [] as $eintrag) {
            is_dir($eintrag) ? rmdir($eintrag) : unlink($eintrag);
        }
        rmdir($this->blobDir);
        parent::tearDown();
    }

    // ------------------------------------------------------- steps 1 to 4

    public function testStep1FindsTheSupplierByItsVatIdWhateverTheNameSays(): void
    {
        $lieferant = $this->lieferant('Sporthaus Beispiel', ['vat_id' => self::USTID]);

        $aufloesung = $this->lesen(['name' => 'Ganz anderer Name', 'vat_id' => 'de 123 456 789']);

        self::assertSame(AufloesungsArt::Treffer, $aufloesung->art);
        self::assertSame(1, $aufloesung->stufe);
        self::assertSame(SupplierKeyKind::VatId, $aufloesung->merkmal);
        self::assertSame($lieferant, $aufloesung->supplierId);
        self::assertSame('Stufe 1 – USt-ID', $aufloesung->stufeText());
        self::assertSame(1, $this->anzahlLieferanten(), 'nothing created');
    }

    public function testStep1AlsoTakesTheTaxNumberHoweverItIsWritten(): void
    {
        $lieferant = $this->lieferant('Sporthaus Beispiel', ['tax_number' => '12 345 67890']);

        $aufloesung = $this->lesen(['name' => 'Sporthaus', 'vat_id' => '', 'iban' => '', 'tax_number' => '12/345/67890']);

        self::assertSame([AufloesungsArt::Treffer, 1, SupplierKeyKind::TaxNumber, $lieferant], [$aufloesung->art, $aufloesung->stufe, $aufloesung->merkmal, $aufloesung->supplierId]);
    }

    public function testStep2FindsTheSupplierByItsIban(): void
    {
        $lieferant = $this->lieferant('Sporthaus Beispiel', ['ibans' => self::IBAN]);

        $aufloesung = $this->lesen(['name' => 'SH Beispiel', 'vat_id' => '', 'iban' => 'DE89 3704 0044 0532 0130 00']);

        self::assertSame([AufloesungsArt::Treffer, 2, SupplierKeyKind::Iban, $lieferant], [$aufloesung->art, $aufloesung->stufe, $aufloesung->merkmal, $aufloesung->supplierId]);
    }

    public function testStep3FindsTheSupplierByItsCreditorId(): void
    {
        $lieferant = $this->lieferant('Stadtwerke Musterstadt', ['creditor_id' => self::GLAEUBIGER]);

        $aufloesung = $this->lesen(['name' => 'SWM', 'vat_id' => '', 'iban' => '', 'creditor_id' => 'de98 zzz0 9999 9999 99']);

        self::assertSame([AufloesungsArt::Treffer, 3, SupplierKeyKind::CreditorId, $lieferant], [$aufloesung->art, $aufloesung->stufe, $aufloesung->merkmal, $aufloesung->supplierId]);
    }

    public function testStep4FindsTheNameWithoutLegalFormCaseAndUmlauts(): void
    {
        $lieferant = $this->lieferant('Getränke Müller');

        $aufloesung = $this->lesen(['name' => 'GETRAENKE MÜLLER GmbH & Co. KG', 'vat_id' => '', 'iban' => '']);

        self::assertSame([AufloesungsArt::Treffer, 4, SupplierKeyKind::Name, $lieferant], [$aufloesung->art, $aufloesung->stufe, $aufloesung->merkmal, $aufloesung->supplierId]);
        self::assertSame(1, $this->anzahlLieferanten());
    }

    public function testStep4AlsoFindsAnAlias(): void
    {
        $lieferant = $this->lieferant('Stadtwerke Musterstadt', ['aliases' => "SWM Versorgung\nMusterstadt Energie"]);

        $aufloesung = $this->lesen(['name' => 'Musterstadt Energie GmbH', 'vat_id' => '', 'iban' => '']);

        self::assertSame([AufloesungsArt::Treffer, 4, $lieferant], [$aufloesung->art, $aufloesung->stufe, $aufloesung->supplierId]);
    }

    public function testTheStepsComeInTheirOrder(): void
    {
        $perIban = $this->lieferant('Sporthaus Beispiel', ['ibans' => self::IBAN]);
        $perUstId = $this->lieferant('Sporthaus Nord', ['vat_id' => self::USTID]);

        $aufloesung = $this->lesen(['name' => 'Sporthaus Beispiel', 'vat_id' => self::USTID, 'iban' => self::IBAN]);

        self::assertSame($perUstId, $aufloesung->supplierId, 'the VAT id (step 1) before IBAN (2) and name (4)');
        self::assertNotSame($perIban, $aufloesung->supplierId);
    }

    public function testSeveralSuppliersOfTheSameNameAreAmbiguousAndNothingIsChosenOrCreated(): void
    {
        $eins = $this->lieferant('Sport Müller');
        $zwei = $this->lieferant('Sport Mueller GmbH');

        $aufloesung = $this->lesen(['name' => 'Sport Müller', 'vat_id' => '', 'iban' => '']);

        self::assertSame(AufloesungsArt::Mehrdeutig, $aufloesung->art);
        self::assertSame(4, $aufloesung->stufe);
        self::assertNull($aufloesung->supplierId);
        self::assertSame([$eins, $zwei], array_map(static fn(array $k): int => $k['id'], $aufloesung->kandidaten));
        self::assertSame(2, $this->anzahlLieferanten());
    }

    public function testOnlySuppliersOfTheDirectionsRoleCount(): void
    {
        $zahler = $this->lieferant('Förderverein Musterstadt', ['ibans' => self::IBAN], SupplierRole::Zahler);

        $aufloesung = $this->lesen(['name' => 'Förderverein Musterstadt', 'vat_id' => '', 'iban' => self::IBAN]);

        self::assertSame(AufloesungsArt::RollePasstNicht, $aufloesung->art, 'a payer is no supplier of an expense');
        self::assertSame(2, $aufloesung->stufe);
        self::assertSame($zahler, $aufloesung->supplierId);
        self::assertSame(1, $this->anzahlLieferanten(), 'and no second partner with the same IBAN');
    }

    public function testAnIdOfTheOtherRoleLetsALaterIdFindTheRightSupplier(): void
    {
        $this->lieferant('Sponsor Beispiel', ['vat_id' => self::USTID], SupplierRole::Zahler);
        $lieferant = $this->lieferant('Beispiel Handel', ['ibans' => self::IBAN]);

        $aufloesung = $this->lesen(['name' => 'Beispiel', 'vat_id' => self::USTID, 'iban' => self::IBAN]);

        self::assertSame([AufloesungsArt::Treffer, 2, $lieferant], [$aufloesung->art, $aufloesung->stufe, $aufloesung->supplierId]);
    }

    public function testAnIncomeFindsThePayer(): void
    {
        $zahler = $this->lieferant('Förderverein Musterstadt', ['ibans' => self::IBAN], SupplierRole::Zahler);

        $aufloesung = $this->lesen(['name' => 'Förderverein', 'vat_id' => '', 'iban' => self::IBAN], 'einnahme');

        self::assertSame([AufloesungsArt::Treffer, $zahler], [$aufloesung->art, $aufloesung->supplierId]);
    }

    public function testASupplierMergedAwayIsFoundAsTheOneItWentInto(): void
    {
        $quelle = $this->lieferant('Sporthaus Beispiel', ['vat_id' => self::USTID]);
        $ziel = $this->lieferant('Sporthaus Beispiel Nord');
        new SupplierZusammenfuehrung($this->pdo(), new SupplierRepository($this->pdo()), new InvoiceRepository($this->pdo()), $this->service(), $this->audit)
            ->ausfuehren($this->tresor, $quelle, $ziel, $this->userId, '', new \DateTimeImmutable());

        $aufloesung = $this->lesen(['name' => 'Sporthaus Beispiel', 'vat_id' => self::USTID]);

        self::assertSame([AufloesungsArt::Treffer, 1, $ziel], [$aufloesung->art, $aufloesung->stufe, $aufloesung->supplierId]);
    }

    // ------------------------------------------------- step 5 (suggestion)

    public function testStep5SuggestsASimilarNameButChoosesAndCreatesNothing(): void
    {
        $aehnlich = $this->lieferant('Sporthaus Beispiel');
        $weniger = $this->lieferant('Sporthaus Beispiele Nord');
        $this->lieferant('Getränke Müller');

        $aufloesung = $this->lesen(['name' => 'Sporthaus Beispeil GmbH', 'vat_id' => '', 'iban' => '']);

        self::assertSame(AufloesungsArt::Vorschlag, $aufloesung->art);
        self::assertSame(5, $aufloesung->stufe);
        self::assertSame($aehnlich, $aufloesung->supplierId, 'the best one first');
        self::assertSame($aehnlich, $aufloesung->kandidaten[0]['id']);
        self::assertGreaterThanOrEqual(0.8, $aufloesung->kandidaten[0]['aehnlichkeit']);
        self::assertNotContains($weniger, array_column($aufloesung->kandidaten, 'id'), 'below the threshold');
        self::assertSame(3, $this->anzahlLieferanten(), 'a suggestion, nothing created');
    }

    // ---------------------------------------------- step 6 (new supplier)

    public function testStep6CreatesTheSupplierForReviewWithWhatTheAiRead(): void
    {
        $this->lieferant('Getränke Müller');

        $aufloesung = $this->lesen([
            'name' => 'Sporthaus Beispiel GmbH',
            'address' => 'Musterstraße 1, 12345 Musterstadt',
            'iban' => 'DE89 3704 0044 0532 0130 00',
            'bic' => 'COBADEFFXXX',
            'vat_id' => self::USTID,
            'email' => 'rechnung@sporthaus.example',
            'website' => 'sporthaus.example',
        ]);

        self::assertSame(AufloesungsArt::Angelegt, $aufloesung->art);
        self::assertSame(6, $aufloesung->stufe);
        self::assertNotNull($aufloesung->supplierId);
        $neu = $this->service()->finde($this->tresor, $aufloesung->supplierId);
        self::assertNotNull($neu);
        self::assertSame('Sporthaus Beispiel GmbH', $neu->data->name);
        self::assertSame([self::IBAN], $neu->data->ibans);
        self::assertSame(self::USTID, $neu->data->vatId);
        self::assertSame('COBADEFFXXX', $neu->data->bic);
        self::assertSame('rechnung@sporthaus.example', $neu->data->email);
        self::assertSame(SupplierRole::Lieferant, $neu->role);
        self::assertSame(SupplierOrigin::Ki, $neu->createdVia);
        self::assertTrue($neu->needsReview);
        self::assertNull($neu->defaultCategoryId);
        self::assertContains('vat_id', $aufloesung->felder);

        // Its keys are there: the next receipt finds it in step 1.
        self::assertSame([$aufloesung->supplierId], new SupplierRepository($this->pdo())->idsWithKey(
            SupplierKeyKind::VatId,
            $this->tresor->blindIndex()->forValue(SupplierKeyKind::VatId->purpose(), self::USTID),
        ));

        $eintraege = array_values(array_filter(
            new AuditLogRepository($this->pdo())->after(0, 100),
            static fn($e): bool => $e->action === AuditAction::LieferantAngelegt->value,
        ));
        self::assertCount(1, $eintraege);
        self::assertNull($eintraege[0]->userId, 'created by the job, not by a person');
        self::assertSame($aufloesung->supplierId, $eintraege[0]->entityId);
        $details = $this->audit->details($eintraege[0], $this->tresor);
        self::assertSame('ki', $details['herkunft'] ?? null);
        self::assertIsInt($details['beleg'] ?? null);

        $this->assertKeinKlartext(['Sporthaus Beispiel', self::IBAN, self::USTID, 'Musterstraße', 'rechnung@sporthaus']);
    }

    public function testValuesTheFormWouldRefuseAreLeftOutOfTheNewSupplier(): void
    {
        $aufloesung = $this->lesen([
            'name' => 'Rasen-Profi UG',
            'iban' => 'DE89 3704 0044 0532 0130 01',
            'vat_id' => '123456789',
            'email' => 'kein-at-zeichen',
            'website' => 'rasen-profi.example',
        ]);

        self::assertSame(AufloesungsArt::Angelegt, $aufloesung->art);
        $neu = $this->service()->finde($this->tresor, (int) $aufloesung->supplierId);
        self::assertNotNull($neu);
        self::assertSame('Rasen-Profi UG', $neu->data->name);
        self::assertSame([], $neu->data->ibans, 'wrong check sum');
        self::assertSame('', $neu->data->vatId);
        self::assertSame('', $neu->data->email);
        self::assertSame('rasen-profi.example', $neu->data->website, 'what is fine stays');
    }

    public function testAnIncomeCreatesAPayer(): void
    {
        $aufloesung = $this->lesen(['name' => 'Sponsor Beispiel AG', 'vat_id' => '', 'iban' => ''], 'einnahme');

        self::assertSame(AufloesungsArt::Angelegt, $aufloesung->art);
        self::assertSame(SupplierRole::Zahler, $this->service()->finde($this->tresor, (int) $aufloesung->supplierId)?->role);
    }

    public function testNoSupplierInTheSuggestionResolvesToNothing(): void
    {
        $aufloesung = $this->lesen(['name' => '', 'address' => '', 'iban' => '', 'bic' => '', 'vat_id' => '', 'email' => '', 'website' => '']);

        self::assertSame([AufloesungsArt::Ohne, 0, null], [$aufloesung->art, $aufloesung->stufe, $aufloesung->supplierId]);
        self::assertSame(0, $this->anzahlLieferanten());
    }

    public function testASecondRunFindsTheCreatedSupplierInsteadOfCreatingItAgain(): void
    {
        $id = $this->beleg();
        $this->allesAbarbeiten([$this->antwort(['name' => 'Sporthaus Beispiel GmbH', 'vat_id' => '', 'iban' => ''])]);
        $erste = $this->aufloesung($id);
        self::assertSame(AufloesungsArt::Angelegt, $erste?->art);

        // "KI erneut versuchen" after all - a new run, the same receipt.
        $this->pdo()->prepare("UPDATE document SET status = 'bereit_zur_auswertung' WHERE id = ?")->execute([$id]);
        KiAuslesung::einreihen(new JobRepository($this->pdo()), $id, new \DateTimeImmutable());
        $this->allesAbarbeiten([$this->antwort(['name' => 'Sporthaus Beispiel GmbH', 'vat_id' => '', 'iban' => ''])]);

        $zweite = $this->aufloesung($id);
        self::assertSame([AufloesungsArt::Treffer, 4, $erste->supplierId], [$zweite?->art, $zweite?->stufe, $zweite?->supplierId]);
        self::assertSame(1, $this->anzahlLieferanten());
        self::assertCount(1, $this->artefakte($id), 'the older run is gone');
    }

    public function testARepeatedStepOfTheSameRunStoresNothingTwice(): void
    {
        $id = $this->beleg();
        $this->allesAbarbeiten([$this->antwort(['name' => 'Sporthaus Beispiel GmbH', 'vat_id' => '', 'iban' => ''])]);
        $jobId = (int) $this->pdo()->query("SELECT id FROM job WHERE typ = 'resolve_supplier'")->fetchColumn();
        $this->pdo()->prepare("UPDATE job SET status = 'offen', step = '', state = '[]' WHERE id = ?")->execute([$jobId]);

        $this->allesAbarbeiten([]);

        self::assertSame(1, $this->anzahlLieferanten());
        self::assertCount(1, $this->artefakte($id));
        self::assertSame('fertig', $this->pdo()->query("SELECT status FROM job WHERE id = {$jobId}")->fetchColumn());
    }

    // --------------------------------------------------------- when not

    public function testADocumentTakenUpMeanwhileIsSkipped(): void
    {
        $id = $this->beleg();
        $this->allesAbarbeiten([$this->antwort(['name' => 'Sporthaus Beispiel GmbH'])], ohneAufloesung: true);
        $this->pdo()->prepare("UPDATE document SET status = 'in_pruefung' WHERE id = ?")->execute([$id]);

        $this->allesAbarbeiten([]);

        self::assertSame('uebersprungen', $this->pdo()->query("SELECT status FROM job WHERE typ = 'resolve_supplier'")->fetchColumn());
        self::assertNull($this->aufloesung($id));
        self::assertSame(0, $this->anzahlLieferanten());
    }

    public function testAFailedAiQueuesNoResolution(): void
    {
        $this->beleg();
        $this->allesAbarbeiten([FakeLlmClient::fixture('extract-v1/kein-json.json'), FakeLlmClient::fixture('extract-v1/kein-json.json')]);

        self::assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM job WHERE typ = 'resolve_supplier'")->fetchColumn());
    }

    public function testAnArchiveDocumentWithASupplierFromItsPathIsSkipped(): void
    {
        $archiv = $this->lieferant('Muster Sport');
        $id = $this->beleg(DocumentSource::Archiv);
        $this->artefakt($id, ArtifactKind::Vorgabe, ['schema' => 'archiv-v1', 'pfad' => 'Muster Sport 14.09.2026.pdf', 'lieferant_id' => $archiv, 'lieferant_name' => 'Muster Sport']);

        $this->allesAbarbeiten([$this->antwort(['name' => 'Ganz Neu GmbH', 'vat_id' => '', 'iban' => ''])]);

        self::assertSame('uebersprungen', $this->pdo()->query("SELECT status FROM job WHERE typ = 'resolve_supplier'")->fetchColumn());
        self::assertSame(1, $this->anzahlLieferanten());
    }

    public function testAReadEInvoiceNamesTheSupplierOverTheAi(): void
    {
        $lieferant = $this->lieferant('Muster Sportartikel', ['vat_id' => 'DE999999999']);
        $id = $this->beleg();
        $this->artefakt($id, ArtifactKind::ERechnung, ['befund' => 'gelesen', 'syntax' => 'cii', 'profil' => 'xrechnung', 'extraktion' => [
            'direction' => 'ausgabe',
            'supplier' => ['name' => 'Muster Sportartikel GmbH', 'vat_id' => 'DE999999999', 'iban' => ''],
        ]]);

        $this->allesAbarbeiten([$this->antwort(['name' => 'Sporthaus Falsch', 'vat_id' => self::USTID])]);

        $aufloesung = $this->aufloesung($id);
        self::assertSame([AufloesungsArt::Treffer, 1, $lieferant], [$aufloesung?->art, $aufloesung?->stufe, $aufloesung?->supplierId]);
    }

    // --------------------------------------------- preselection (prompt)

    public function testThePromptNamesTheKnownSuppliersTheReceiptHintsAtAndNoOthers(): void
    {
        $this->lieferant('Sporthaus Beispiel');
        $this->lieferant('Stadtwerke Musterstadt', ['ibans' => self::IBAN]);
        $this->lieferant('Getränke Müller');
        $id = $this->beleg(text: "Sporthaus Beispiel GmbH · Musterstraße 1\nRechnung RE-2026-0815\nZahlbar auf DE89 3704 0044 0532 0130 00");

        $this->allesAbarbeiten([$this->antwort(['name' => 'Sporthaus Beispiel GmbH', 'vat_id' => '', 'iban' => ''])]);

        $system = $this->client->anfragen[0]['anfrage']->system;
        self::assertStringContainsString("\n- Stadtwerke Musterstadt\n- Sporthaus Beispiel", $system, 'by id first, then by name');
        self::assertStringNotContainsString('Getränke Müller', $system, 'never the whole list');
        self::assertStringNotContainsString('Keine Vorauswahl.', $system);
        self::assertSame([AufloesungsArt::Treffer, 4], [$this->aufloesung($id)?->art, $this->aufloesung($id)?->stufe]);
    }

    public function testAPhotoWithoutTextHasNoPreselection(): void
    {
        $this->lieferant('Sporthaus Beispiel');
        $this->beleg();

        $this->allesAbarbeiten([$this->antwort([])]);

        self::assertStringContainsString("Bekannte Lieferanten:\nKeine Vorauswahl.", $this->client->anfragen[0]['anfrage']->system);
    }

    public function testTheMigrationQueuesTheResolutionForEvaluatedDocuments(): void
    {
        $ausgewertet = $this->beleg(status: DocumentStatus::Ausgewertet, ohneJob: true);
        $this->beleg(status: DocumentStatus::InPruefung, ohneJob: true);
        $this->pdo()->exec("DELETE FROM job");

        $this->pdo()->exec((string) file_get_contents($this->migrationsDir() . '/035_resolve_supplier.sql'));

        self::assertSame(
            [['resolve_supplier', 'document', $ausgewertet, 'session', 'offen']],
            array_map(
                static fn(array $z): array => [$z['typ'], $z['ref_type'], (int) $z['ref_id'], $z['executor'], $z['status']],
                $this->pdo()->query('SELECT * FROM job')->fetchAll(),
            ),
        );
    }

    // ---------------------------------------------------------- scaffolding

    /**
     * Reads a fresh receipt whose AI answer names this supplier and runs
     * both jobs - the resolution as stored.
     *
     * @param array<string, string> $supplier laid over the recorded answer's
     */
    private function lesen(array $supplier, string $richtung = 'ausgabe'): Aufloesung
    {
        $id = $this->beleg();
        $this->allesAbarbeiten([$this->antwort($supplier, $richtung)]);
        self::assertSame('fertig', $this->pdo()->query("SELECT status FROM job WHERE typ = 'resolve_supplier' ORDER BY id DESC LIMIT 1")->fetchColumn());

        return $this->aufloesung($id) ?? self::fail('no resolution stored');
    }

    /**
     * The recorded valid answer with $supplier laid over its `supplier`.
     *
     * @param array<string, string> $supplier
     */
    private function antwort(array $supplier, string $richtung = 'ausgabe'): LlmAntwort
    {
        $huelle = json_decode(FakeChatHttp::fixture('extract-v1/gueltig.json')->body, true, flags: JSON_THROW_ON_ERROR);
        $inhalt = json_decode($huelle['choices'][0]['message']['content'], true, flags: JSON_THROW_ON_ERROR);
        $inhalt['supplier'] = [...$inhalt['supplier'], ...$supplier];
        $inhalt['direction'] = $richtung;
        $inhalt['category_id'] = null;
        $huelle['choices'][0]['message']['content'] = json_encode($inhalt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return LlmAntwort::ausChatCompletion(json_encode($huelle, JSON_THROW_ON_ERROR), 'gpt-6-luna', 1200);
    }

    /**
     * A supplier as the page creates it.
     *
     * @param array<string, string> $felder
     */
    private function lieferant(string $name, array $felder = [], SupplierRole $rolle = SupplierRole::Lieferant): int
    {
        return $this->service()->anlegen($this->tresor, ['name' => $name, 'rolle' => $rolle->value, ...$felder], new \DateTimeImmutable())->id;
    }

    /** A photographed receipt waiting for the AI - or with a text layer the AI reads instead. */
    private function beleg(
        DocumentSource $quelle = DocumentSource::Einreichung,
        ?string $text = null,
        DocumentStatus $status = DocumentStatus::BereitZurAuswertung,
        bool $ohneJob = false,
    ): int {
        $blob = $this->blobService()->storeString(FakeJpeg::bauen(20, 20), new BlobMeta(MagicBytes::JPEG, 'original'), $this->tresor)->id;
        $id = new DocumentRepository($this->pdo())->insert(
            $quelle,
            null,
            [$blob],
            $this->tresor->sealDataKey(DataKey::generate()),
            new \DateTimeImmutable(),
            $status,
            $text === null ? OcrStatus::Uebersprungen : OcrStatus::Fertig,
        );
        if ($text !== null) {
            $this->artefakt($id, ArtifactKind::Text, ['befund' => 'gelesen', 'brauchbar' => true, 'seiten' => [$text]]);
        }
        if (!$ohneJob) {
            KiAuslesung::einreihen(new JobRepository($this->pdo()), $id, new \DateTimeImmutable());
        }

        return $id;
    }

    /**
     * An artifact as its job would store it: its own data key, AAD with the row id.
     *
     * @param array<string, mixed> $daten
     */
    private function artefakt(int $documentId, ArtifactKind $kind, array $daten): void
    {
        $repository = new DocumentArtifactRepository($this->pdo());
        $schluessel = DataKey::generate();
        $id = $repository->insert($documentId, $kind, 0, null, $this->tresor->sealDataKey($schluessel), null, JobExecutor::Session, null, new \DateTimeImmutable());
        $repository->setzeDaten($id, FieldCipher::encrypt($schluessel, json_encode($daten, JSON_THROW_ON_ERROR), new FieldContext('document_artifact', $id, 'data_enc')));
    }

    /**
     * Every job a signed-in session would run - `ai_extract` with these
     * answers, then `resolve_supplier` - phases as in App\Api\JobController.
     *
     * @param list<LlmAntwort|\Throwable> $antworten
     */
    private function allesAbarbeiten(array $antworten, bool $ohneAufloesung = false): void
    {
        $this->client = new FakeLlmClient($antworten);
        $berechtigungen = new UserAccessRepository($this->pdo())->berechtigungen($this->userId);
        $runner = fn(): JobRunner => new JobRunner(new JobRepository($this->pdo()), [
            KiAuslesungFabrik::bauen($this->pdo(), $this->blobService(), $this->crypto, $this->client),
            ...($ohneAufloesung ? [] : [$this->handler()]),
        ]);
        for ($i = 0; $i < 30; $i++) {
            $lauf = $runner()->schritt($berechtigungen, $this->tresor, new \DateTimeImmutable());
            if ($lauf->aufrufAusstehend()) {
                $lauf = $runner()->nachAufruf($berechtigungen, $this->tresor, $lauf, ($lauf->aufruf?->ausfuehren)(), new \DateTimeImmutable());
            }
            if ($lauf->status !== JobLaufStatus::Gearbeitet) {
                break;
            }
        }
        self::assertSame([], $this->pdo()->query("SELECT typ, step, last_error FROM job WHERE status = 'fehler'")->fetchAll(), 'no job error');
    }

    private function handler(): LieferantenAufloesung
    {
        return KiAuslesungFabrik::lieferanten($this->pdo(), $this->blobService(), $this->crypto, $this->audit);
    }

    private function aufloesung(int $documentId): ?Aufloesung
    {
        $document = new DocumentRepository($this->pdo())->find($documentId) ?? self::fail('no document');

        return $this->handler()->ergebnis($document, $this->tresor);
    }

    private function service(): SupplierService
    {
        return new SupplierService($this->pdo(), new SupplierRepository($this->pdo()), new CategoryRepository($this->pdo()));
    }

    private function anzahlLieferanten(): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM supplier WHERE merged_into IS NULL')->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function artefakte(int $documentId): array
    {
        $stmt = $this->pdo()->prepare("SELECT * FROM document_artifact WHERE document_id = ? AND kind = 'lieferant'");
        $stmt->execute([$documentId]);

        return $stmt->fetchAll();
    }

    /**
     * Nothing of the supplier in plaintext: not in the job rows, the
     * artifacts, the supplier rows, the keys or the audit log.
     *
     * @param list<string> $inhalte
     */
    private function assertKeinKlartext(array $inhalte): void
    {
        $zeilen = [
            ...$this->pdo()->query('SELECT * FROM job')->fetchAll(),
            ...$this->pdo()->query('SELECT * FROM document_artifact')->fetchAll(),
            ...$this->pdo()->query('SELECT * FROM supplier')->fetchAll(),
            ...$this->pdo()->query('SELECT * FROM supplier_key')->fetchAll(),
            ...$this->pdo()->query('SELECT * FROM audit_log')->fetchAll(),
        ];
        foreach ($zeilen as $zeile) {
            foreach ($zeile as $wert) {
                foreach ($inhalte as $klartext) {
                    self::assertStringNotContainsStringIgnoringCase($klartext, (string) $wert);
                }
            }
        }
    }

    private function blobService(): BlobService
    {
        $repository = new BlobRepository($this->pdo());

        return new BlobService($repository, new DbBlobBackend($repository), new FsBlobBackend($this->blobDir));
    }
}
