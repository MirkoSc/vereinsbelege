<?php

declare(strict_types=1);

namespace App\App;

use App\Domain\AuditAction;
use App\Domain\Berechtigungen;
use App\Domain\Permission;
use App\Domain\Zugriffsbereich;
use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Service\Account\SessionVault;
use App\Service\Audit\AuditLog;
use App\Domain\Differenzgrund;
use App\Service\Bank\Abgleich;
use App\Service\Bank\AbgleichFilter;
use App\Service\Bank\Abgleichsparameter;
use App\Service\Bank\BankRuleViolation;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Processing\Betrag;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * Matching receipt <-> booking /app/abgleich (M10-2, issue #66, docs/spec/
 * 04-bank-und-abgleich.md section 5 "Punktebewertung", E-35): runs the
 * automatic allocation, lists the suggestions with the reasons for their
 * points, accepts one - and holds the points and thresholds as settings.
 *
 * Everything needs `matching.edit` (app/src/routes.php) and works within
 * its scope. Receipts and bookings are vault data: without an unlocked
 * vault the page says so and neither scores nor writes. Nothing of either
 * side goes into a URL, a flash message or the audit log - ids and counts
 * only.
 *
 * The matching page (M10-4, issue #68, E-41) adds both lists side by side
 * with filters and paging, one selected receipt with its allocations
 * ("Lösen"), the difference and the bookings that could take it (manual
 * allocation with a part amount), and "Verwerfen" for a suggestion.
 * Account, period, receipt and page travel in the URL; amount and search
 * text are club data and only come by POST (/app/abgleich/filter), which
 * renders the page directly.
 */
final readonly class AbgleichController
{
    private const string ZIEL = '/app/abgleich';

    private const string BERECHNET = '/app/abgleich?berechnen=1';

    private const string TRESOR_GESPERRT = 'Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte melden Sie sich neu an.';

    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private Abgleich $abgleich,
        private AuditLog $audit,
    ) {
    }

    public function seite(Request $request): ResponseInterface
    {
        $this->session->start();
        // Opening the page scores nothing; only "Abgleich berechnen"
        // (?berechnen=1, also where the writes return to) decrypts and scores.
        $berechnen = ($request->query['berechnen'] ?? '') === '1';

        return $this->anzeigen($request, $this->abgleich->parameter(), null, [], berechnen: $berechnen, filter: AbgleichFilter::aus($request->query));
    }

    /** Amount and search text by POST - rendered, never put into a URL. */
    public function filtern(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure();
        }

        return $this->anzeigen($request, $this->abgleich->parameter(), null, [], filter: AbgleichFilter::aus([], self::felder($request)));
    }

    public function zuordnen(Request $request): ResponseInterface
    {
        return $this->schreiben($request, function (Vault $tresor, int $beleg) use ($request): string {
            $buchung = self::id($request, 'buchung');
            $betrag = Betrag::parse(self::text($request, 'betrag'));
            if ($buchung === null) {
                throw new BankRuleViolation('Bitte eine Buchung wählen.');
            }
            if ($betrag === null || $betrag <= 0) {
                throw new BankRuleViolation('Bitte einen Betrag größer als null angeben, z. B. 12,50.', 'betrag');
            }
            $this->abgleich->zuordnenVonHand($tresor, $this->bereich(), $beleg, $buchung, $betrag, $this->session->userId(), $request->ip, new \DateTimeImmutable());

            return 'Beleg und Buchung sind einander zugeordnet.';
        });
    }

    public function loesen(Request $request): ResponseInterface
    {
        return $this->schreiben($request, function (Vault $tresor, int $beleg) use ($request): string {
            $zuordnung = self::id($request, 'zuordnung') ?? throw new BankRuleViolation('Diese Zuordnung gibt es nicht (mehr).');
            $this->abgleich->loesen($tresor, $this->bereich(), $beleg, $zuordnung, $this->session->userId(), $request->ip, new \DateTimeImmutable());

            return 'Zuordnung gelöst. Beleg und Buchung sind wieder offen, soweit nichts anderes sie ausgleicht.';
        });
    }

    public function differenz(Request $request): ResponseInterface
    {
        return $this->schreiben($request, function (Vault $tresor, int $beleg) use ($request): string {
            $wert = self::text($request, 'grund');
            $grund = $wert === 'oeffnen' ? null : (Differenzgrund::tryFrom($wert) ?? throw new BankRuleViolation('Bitte einen Grund für die Differenz wählen.', 'grund'));
            $this->abgleich->differenz($tresor, $this->bereich(), $beleg, $grund, $this->session->userId(), $request->ip, new \DateTimeImmutable());

            return $grund === null ? 'Differenz wieder geöffnet – der Rest ist wieder offen.' : 'Differenz abgeschlossen – der Beleg gilt als ausgeglichen.';
        });
    }

    public function verwerfen(Request $request): ResponseInterface
    {
        return $this->schreiben($request, function (Vault $tresor, int $beleg) use ($request): string {
            $buchung = self::id($request, 'buchung') ?? throw new BankRuleViolation('Bitte eine Buchung wählen.');
            $userId = $this->session->userId();
            if (self::text($request, 'zuruecknehmen') === '1') {
                if ($this->abgleich->wiederVorschlagen($this->bereich(), $beleg, $buchung)) {
                    $this->audit->record(AuditAction::AbgleichVorschlagZurueckgenommen, $userId, $request->ip, details: ['invoice_id' => $beleg, 'transaction_id' => $buchung]);
                }

                return 'Das Paar wird wieder bewertet und vorgeschlagen.';
            }
            if ($this->abgleich->verwerfen($this->bereich(), $beleg, $buchung, $userId, new \DateTimeImmutable())) {
                $this->audit->record(AuditAction::AbgleichVorschlagVerworfen, $userId, $request->ip, details: ['invoice_id' => $beleg, 'transaction_id' => $buchung]);
            }

            return 'Vorschlag verworfen – dieses Paar wird nicht mehr vorgeschlagen oder automatisch zugeordnet.';
        });
    }

    /**
     * The common frame of the writes on one receipt: CSRF, unlocked vault,
     * the receipt id, then back to the page - to the suggestions when the
     * form came from there, otherwise to the receipt with the structural
     * filters. A refused rule becomes the error message.
     *
     * @param \Closure(Vault, int): string $aktion returns the message
     */
    private function schreiben(Request $request, \Closure $aktion): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure();
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt();
        }
        $filter = AbgleichFilter::aus(self::felder($request));
        $ziel = self::text($request, 'zurueck') === 'vorschlaege' ? self::BERECHNET : $filter->url();
        $beleg = self::id($request, 'beleg');
        if ($beleg === null) {
            return Response::redirect($ziel);
        }

        try {
            $this->session->flash($aktion($tresor, $beleg));
        } catch (BankRuleViolation $e) {
            $this->session->flash($e->getMessage(), FlashArt::Fehler);
        }

        return Response::redirect($ziel . ($ziel === self::BERECHNET ? '' : '#beleg'));
    }

    /**
     * The form fields as strings.
     *
     * @return array<string, string>
     */
    private static function felder(Request $request): array
    {
        $felder = [];
        foreach ($request->post as $name => $wert) {
            if (is_string($name) && is_string($wert)) {
                $felder[$name] = $wert;
            }
        }

        return $felder;
    }

    private static function id(Request $request, string $feld): ?int
    {
        $wert = self::text($request, $feld);

        return ctype_digit($wert) && strlen($wert) <= 18 && (int) $wert > 0 ? (int) $wert : null;
    }

    public function ausfuehren(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure();
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt();
        }

        $ergebnis = $this->abgleich->automatischZuordnen($tresor, $this->bereich(), $this->session->userId(), $request->ip, new \DateTimeImmutable());
        $this->audit->record(AuditAction::AbgleichAusgefuehrt, $this->session->userId(), $request->ip, details: $ergebnis);
        $this->session->flash(match ($ergebnis['angelegt']) {
            0 => 'Keine Zuordnung war eindeutig genug, um sie automatisch anzulegen. Die Vorschläge stehen unten.',
            1 => '1 Zuordnung wurde automatisch angelegt.',
            default => $ergebnis['angelegt'] . ' Zuordnungen wurden automatisch angelegt.',
        } . ($ergebnis['uebersprungen'] > 0 ? ' ' . $ergebnis['uebersprungen'] . ' wurden inzwischen anderweitig geändert und übersprungen.' : ''));

        return Response::redirect(self::BERECHNET);
    }

    public function uebernehmen(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure();
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt();
        }
        $beleg = self::text($request, 'beleg');
        $buchung = self::text($request, 'buchung');
        $betrag = self::text($request, 'betrag');
        if (!ctype_digit($beleg) || !ctype_digit($buchung) || !ctype_digit($betrag) || strlen($betrag) > 12) {
            return Response::redirect(self::ZIEL);
        }

        try {
            $this->abgleich->uebernehmen($tresor, $this->bereich(), (int) $beleg, (int) $buchung, (int) $betrag, $this->session->userId(), $request->ip, new \DateTimeImmutable());
            $this->session->flash('Vorschlag übernommen: Beleg und Buchung sind einander zugeordnet.');
        } catch (BankRuleViolation $e) {
            $this->session->flash($e->getMessage(), FlashArt::Fehler);
        }

        return Response::redirect(self::BERECHNET);
    }

    public function einstellungen(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure();
        }

        $felder = [];
        foreach (self::feldnamen() as $feld) {
            $felder[$feld] = self::text($request, $feld);
        }
        try {
            $neu = Abgleichsparameter::ausFormular($felder);
        } catch (BankRuleViolation $e) {
            return $this->anzeigen($request, $this->abgleich->parameter(), $e, $felder, 422);
        }

        $alt = $this->abgleich->parameter()->alsWerte();
        if ($this->abgleich->parameterSpeichern($neu)) {
            $this->audit->record(AuditAction::EinstellungAbgleich, $this->session->userId(), $request->ip, details: ['felder' => self::geaenderteFelder($alt, $neu->alsWerte())]);
        }
        $this->session->flash('Punktebewertung gespeichert. Sie gilt ab dem nächsten Abgleich; bestehende Zuordnungen bleiben.');

        return Response::redirect(self::ZIEL . '#einstellungen');
    }

    /**
     * @return list<string> the form fields of the settings
     */
    public static function feldnamen(): array
    {
        return [
            ...array_map(static fn(string $s): string => 'punkte_' . $s, array_keys(Abgleichsparameter::PUNKTE_START)),
            ...array_keys(Abgleichsparameter::WERTE),
        ];
    }

    /**
     * @param array<string, string> $felder what was sent, shown again after an error
     */
    private function anzeigen(Request $request, Abgleichsparameter $parameter, ?BankRuleViolation $fehler, array $felder, int $status = 200, bool $berechnen = false, ?AbgleichFilter $filter = null): ResponseInterface
    {
        $tresor = $this->tresor($request);
        $filter ??= new AbgleichFilter();
        $bereich = $this->bereich();

        return Response::html($this->view->render('app/abgleich', [
            'title' => 'Abgleich',
            'flash' => $this->session->pullFlash(),
            'entsperrt' => $tresor !== null,
            'filter' => $filter,
            'konten' => $tresor === null ? [] : $this->abgleich->konten($tresor),
            'liste' => $tresor === null ? null : $this->abgleich->liste($tresor, $bereich, $filter),
            'detail' => $tresor === null || $filter->belegId === null ? null : $this->abgleich->detail($tresor, $bereich, $filter->belegId, $filter),
            'stand' => $tresor === null || !$berechnen ? null : $this->abgleich->planen($tresor, $bereich),
            'parameter' => $parameter,
            'fehler' => $fehler,
            'felder' => $felder,
        ], Area::App), $status);
    }

    /**
     * @param array<string, mixed> $alt
     * @param array<string, mixed> $neu
     *
     * @return list<string>
     */
    private static function geaenderteFelder(array $alt, array $neu): array
    {
        $geaendert = [];
        foreach ($neu as $name => $wert) {
            if ($name === 'punkte' && is_array($wert)) {
                foreach ($wert as $signal => $punkte) {
                    if (($alt['punkte'][$signal] ?? null) !== $punkte) {
                        $geaendert[] = 'punkte_' . $signal;
                    }
                }
            } elseif (($alt[$name] ?? null) !== $wert) {
                $geaendert[] = $name;
            }
        }

        return $geaendert;
    }

    private function bereich(): Zugriffsbereich
    {
        return ($this->view->berechtigungen() ?? Berechtigungen::keine())->zugriffsbereich(Permission::MatchingEdit);
    }

    private static function text(Request $request, string $feld): string
    {
        $wert = $request->post[$feld] ?? '';

        return is_string($wert) ? $wert : '';
    }

    private function tresor(Request $request): ?Vault
    {
        try {
            return $this->sessionVault->unlock(Cookie::vaultKeyFrom($request));
        } catch (CryptoException) {
            return null;
        }
    }

    private function gesperrt(): ResponseInterface
    {
        $this->session->flash('Nichts zugeordnet. ' . self::TRESOR_GESPERRT, FlashArt::Fehler);

        return Response::redirect(self::ZIEL);
    }

    private function csrfFailure(): ResponseInterface
    {
        $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

        return Response::redirect(self::ZIEL);
    }
}
