<?php

declare(strict_types=1);

namespace App\App;

use App\Domain\AuditAction;
use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\AuditLogRepository;
use App\Repository\UserRepository;
use App\Service\Account\SessionVault;
use App\Service\Audit\AuditFilter;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\View\Area;
use App\View\View;

/**
 * The audit log page (issue #21/M3-8, docs/spec/01-sicherheit.md section
 * 6): the list with its filters, and the integrity check of the chain.
 *
 * Behind `audit.view` (app/src/routes.php) - Admin, Vorstand and
 * Kassenprüfer in the shipped roles. That is why it lives in /app and not
 * in /admin: /admin needs an `admin.*` right, which Vorstand and
 * Kassenprüfer do not have.
 *
 * The plaintext columns (time, account, action, object) show for everyone
 * with the right. The details are vault ciphertext and show only in a
 * session whose vault is unlocked - the same rule as every other club
 * datum. The check needs no vault at all: the chain is computed over
 * what is stored.
 */
final readonly class AuditController
{
    /** Rows per page of the list. */
    public const int SEITE = 50;

    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private AuditLogRepository $eintraege,
        private AuditLog $audit,
        private UserRepository $users,
        private ServerCrypto $crypto,
    ) {
    }

    public function liste(Request $request): ResponseInterface
    {
        $this->session->start();

        $filter = AuditFilter::fromQuery($request->query);
        $vor = $request->query['vor'] ?? '';
        $vorId = is_string($vor) && ctype_digit($vor) && (int) $vor > 0 ? (int) $vor : null;

        // One more than shown: whether there is an older page at all.
        $treffer = $this->eintraege->page($filter, $vorId, self::SEITE + 1);
        $weitere = count($treffer) > self::SEITE;
        $treffer = array_slice($treffer, 0, self::SEITE);

        $namen = $this->namen();
        $tresor = $this->tresor($request);

        $zeilen = [];
        foreach ($treffer as $eintrag) {
            $aktion = $eintrag->aktion();
            $zeilen[] = [
                'id' => $eintrag->id,
                'zeit' => new \DateTimeImmutable($eintrag->ts),
                'benutzer' => $eintrag->userId === null ? null : ($namen[$eintrag->userId] ?? 'Konto #' . $eintrag->userId),
                'aktion' => $aktion?->label() ?? $eintrag->action,
                'objekt' => self::objekt($eintrag->entity, $eintrag->entityId, $namen),
                'ip' => $eintrag->ipHash === null ? null : substr(bin2hex($eintrag->ipHash), 0, 8),
                'details' => match (true) {
                    $eintrag->detailsEnc === null => [],
                    $tresor === null => null,
                    default => self::detailText($this->audit->details($eintrag, $tresor)),
                },
            ];
        }

        $naechste = null;
        if ($weitere && $treffer !== []) {
            $naechste = '/app/audit?' . http_build_query([...$filter->toQuery(), 'vor' => (string) end($treffer)->id]);
        }

        asort($namen, SORT_NATURAL | SORT_FLAG_CASE);

        return Response::html($this->view->render('app/audit', [
            'title' => 'Audit-Log',
            'scripts' => ['/js/audit.js'],
            'csrf' => $this->session->csrfToken(),
            'flash' => $this->session->pullFlash(),
            'filter' => $filter,
            'aktionen' => AuditAction::cases(),
            'entities' => AuditAction::entities(),
            'namen' => $namen,
            'zeilen' => $zeilen,
            'naechste' => $naechste,
            'blaettert' => $vorId !== null,
            'entsperrt' => $tresor !== null,
            'anzahl' => $this->audit->anzahl(),
        ], Area::App));
    }

    /**
     * One step of the integrity check (JSON, driven by public/js/audit.js).
     * `nach_id` is where the previous step ended, 0 for the start. The
     * answer carries the head hash once the check is through - the control
     * value to note down outside the database.
     */
    public function pruefen(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return Response::json(['fehler' => 'Sitzung abgelaufen – bitte die Seite neu laden.'], 403);
        }

        $nach = $request->post['nach_id'] ?? '0';
        $nachId = is_string($nach) && ctype_digit($nach) ? (int) $nach : 0;

        $ergebnis = $this->audit->pruefeAbschnitt($nachId);
        $fertig = !$ergebnis->intakt() || $ergebnis->geprueft < AuditLog::PRUEF_SCHRITT;

        return Response::json([
            'geprueft' => $ergebnis->geprueft,
            'nach_id' => $ergebnis->letzteId,
            'gesamt' => $this->audit->anzahl(),
            'fertig' => $fertig,
            'bruch' => $ergebnis->intakt() ? null : [
                'id' => $ergebnis->bruchId,
                'meldung' => $ergebnis->bruch?->meldung(),
            ],
            'kopf' => $fertig && $ergebnis->intakt() && $ergebnis->letzteId > 0 ? bin2hex($ergebnis->letzterHash) : null,
        ]);
    }

    /**
     * Display names by account id - operating data under the server key,
     * shown here to whoever may read the log.
     *
     * @return array<int, string>
     */
    private function namen(): array
    {
        $namen = [];
        foreach ($this->users->all() as $user) {
            $namen[$user->id] = $this->crypto->decrypt($user->displayNameEnc);
        }

        return $namen;
    }

    private function tresor(Request $request): ?Vault
    {
        try {
            return $this->sessionVault->unlock(Cookie::vaultKeyFrom($request));
        } catch (CryptoException) {
            return null;
        }
    }

    /**
     * @param array<int, string> $namen
     */
    private static function objekt(?string $entity, ?int $entityId, array $namen): ?string
    {
        if ($entity === null || $entityId === null) {
            return null;
        }
        if ($entity === 'user' && isset($namen[$entityId])) {
            return 'Benutzer ' . $namen[$entityId];
        }

        return AuditAction::entityLabel($entity) . ' #' . $entityId;
    }

    /**
     * The decrypted details as short "key: value" lines. Null when they do
     * not open.
     *
     * @param array<string, mixed>|null $details
     * @return list<string>|null
     */
    private static function detailText(?array $details): ?array
    {
        if ($details === null) {
            return null;
        }

        $zeilen = [];
        foreach ($details as $schluessel => $wert) {
            $zeilen[] = str_replace('_', ' ', (string) $schluessel) . ': ' . self::wert($wert);
        }

        return $zeilen;
    }

    private static function wert(mixed $wert): string
    {
        return match (true) {
            is_bool($wert) => $wert ? 'ja' : 'nein',
            $wert === null => '–',
            is_array($wert) => $wert === [] ? '–' : implode(', ', array_map(self::wert(...), $wert)),
            is_scalar($wert) => (string) $wert,
            default => '?',
        };
    }
}
