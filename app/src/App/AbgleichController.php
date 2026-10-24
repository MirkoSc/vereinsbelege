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
use App\Service\Bank\Abgleich;
use App\Service\Bank\Abgleichsparameter;
use App\Service\Bank\BankRuleViolation;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
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
 * only. The full matching page (two lists, manual allocation, discarding
 * suggestions) is M10-4.
 */
final readonly class AbgleichController
{
    private const string ZIEL = '/app/abgleich';

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

        return $this->anzeigen($request, $this->abgleich->parameter(), null, []);
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

        return Response::redirect(self::ZIEL);
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
        if (!ctype_digit($beleg) || !ctype_digit($buchung)) {
            return Response::redirect(self::ZIEL);
        }

        try {
            $this->abgleich->uebernehmen($tresor, $this->bereich(), (int) $beleg, (int) $buchung, $this->session->userId(), $request->ip, new \DateTimeImmutable());
            $this->session->flash('Vorschlag übernommen: Beleg und Buchung sind einander zugeordnet.');
        } catch (BankRuleViolation $e) {
            $this->session->flash($e->getMessage(), FlashArt::Fehler);
        }

        return Response::redirect(self::ZIEL);
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
    private function anzeigen(Request $request, Abgleichsparameter $parameter, ?BankRuleViolation $fehler, array $felder, int $status = 200): ResponseInterface
    {
        $tresor = $this->tresor($request);

        return Response::html($this->view->render('app/abgleich', [
            'title' => 'Abgleich',
            'flash' => $this->session->pullFlash(),
            'entsperrt' => $tresor !== null,
            'stand' => $tresor === null ? null : $this->abgleich->planen($tresor, $this->bereich()),
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
