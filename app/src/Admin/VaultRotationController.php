<?php

declare(strict_types=1);

namespace App\Admin;

use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\UserRepository;
use App\Service\Account\SessionVault;
use App\Service\Account\UserAdministration;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Mail\Mailer;
use App\Service\Tresor\RotationException;
use App\Service\Tresor\Schluesselrotation;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * /admin/tresor/rotation (issue #90, docs/spec/01-sicherheit.md section 2
 * "Schlüsselrotation"): change the vault key pair, re-seal every data key,
 * hand out a new recovery key.
 *
 * Start, confirmation and finish are plain form posts; the re-sealing in
 * between is a step chain the page's JavaScript drives (tresor-rotation.js),
 * one short JSON request at a time. Every step needs this session's key
 * ring - the new generation with the old one behind it - which the start
 * stores and every later login during the rotation builds again
 * (App\Service\Account\LoginService).
 *
 * While a rotation runs, App\Http\LoginGuard keeps everything else in /app
 * and /admin closed and sends accounts with `admin.vault_grant` here.
 *
 * ---------------------------------------------------------------------
 * RIGHT REQUIRED: `admin.vault_grant` (app/src/routes.php), CSRF on every
 * write.
 * ---------------------------------------------------------------------
 */
final readonly class VaultRotationController
{
    /** The session's memory of the step chain (cursor, unreadable rows). */
    private const string FORTSCHRITT = 'tresor_rotation';

    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private Schluesselrotation $rotation,
        private UserAdministration $verwaltung,
        private UserRepository $users,
        private Mailer $mailer,
        private ServerCrypto $crypto,
    ) {
    }

    public function seite(Request $request): ResponseInterface
    {
        $this->session->start();
        $ring = $this->ring($request);
        try {
            $stand = $this->rotation->stand($this->fortschritt());
        } catch (RotationException $e) {
            return Response::html($this->view->render('error', [
                'title' => 'Schlüsselrotation',
                'message' => $e->getMessage(),
                'startseite' => '/admin/update',
            ], Area::Admin), 409);
        }

        $schluessel = null;
        if ($stand->laeuft && !$stand->schluesselBestaetigt && $ring !== null) {
            try {
                $schluessel = $this->rotation->neuerWiederherstellungsschluessel($ring)->groups();
            } catch (RotationException) {
                $schluessel = null;
            }
        }

        return Response::html($this->view->render('admin/tresor-rotation', [
            'title' => 'Tresor-Schlüssel wechseln',
            'scripts' => ['/js/tresor-rotation.js'],
            'csrf' => $this->session->csrfToken(),
            'flash' => $this->session->pullFlash(),
            'stand' => $stand,
            'entsperrt' => $ring !== null,
            'ringVollstaendig' => $ring !== null && $stand->laeuft && $stand->alteVersion !== null
                && $ring->version === $stand->neueVersion && $ring->canOpen($stand->alteVersion),
            'schluesselGruppen' => $schluessel,
        ], Area::Admin));
    }

    public function starten(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->zurueck('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);
        }
        if (($request->post['verstanden'] ?? '') !== '1') {
            return $this->zurueck('Bitte bestätigen Sie, dass Sie die Folgen gelesen haben.', FlashArt::Fehler);
        }
        $sitzung = $this->ring($request);
        if ($sitzung === null) {
            return $this->zurueck('Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte neu anmelden.', FlashArt::Fehler);
        }

        try {
            $ring = $this->rotation->starten($sitzung, (int) $this->session->userId(), $request->ip, new \DateTimeImmutable());
        } catch (RotationException $e) {
            return $this->zurueck($e->getMessage(), FlashArt::Fehler);
        }
        unset($_SESSION[self::FORTSCHRITT]);
        $this->benachrichtigen('Der Tresor-Schlüssel des Vereins wird gewechselt. Bis zum Abschluss sind Belege gesperrt.');

        $this->session->flash(
            'Der neue Tresor-Schlüssel ist erzeugt. Bitte jetzt den neuen Wiederherstellungsschlüssel sichern und bestätigen.',
            FlashArt::Ok,
        );

        return Response::redirect('/admin/tresor/rotation')->withCookie(
            Cookie::vaultKey($this->sessionVault->store($ring), Request::httpsFromGlobals()),
        );
    }

    public function bestaetigen(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->zurueck('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);
        }
        $ring = $this->ring($request);
        if ($ring === null) {
            return $this->zurueck('Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte neu anmelden.', FlashArt::Fehler);
        }

        try {
            $passt = $this->rotation->bestaetigen(
                $ring,
                (string) ($request->post['letzte_gruppe'] ?? ''),
                (int) $this->session->userId(),
                $request->ip,
                new \DateTimeImmutable(),
            );
        } catch (RotationException $e) {
            return $this->zurueck($e->getMessage(), FlashArt::Fehler);
        }

        return $passt
            ? $this->zurueck('Der neue Wiederherstellungsschlüssel ist bestätigt.', FlashArt::Ok)
            : $this->zurueck(
                'Die eingegebene Gruppe stimmt nicht. Bitte die letzte Gruppe des neuen Schlüssels genau eingeben.',
                FlashArt::Fehler,
            );
    }

    /**
     * One step of the re-sealing, JSON. The browser repeats it until the
     * answer says nothing is left.
     */
    public function schritt(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return Response::json(['fehler' => 'Sitzung abgelaufen – bitte die Seite neu laden.'], 403);
        }
        $ring = $this->ring($request);
        if ($ring === null) {
            return Response::json(['fehler' => 'Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte neu anmelden.'], 409);
        }

        try {
            [$stand, $fortschritt] = $this->rotation->umschluesseln($ring, $this->fortschritt(), new \DateTimeImmutable());
        } catch (RotationException $e) {
            return Response::json(['fehler' => $e->getMessage()], 409);
        } catch (\Throwable $e) {
            // Class and message only (CLAUDE.md section 4); nothing of a
            // receipt is in either.
            return Response::json(['fehler' => 'Schritt fehlgeschlagen: ' . $e::class], 500);
        }
        $_SESSION[self::FORTSCHRITT] = $fortschritt;

        return Response::json($stand->toArray());
    }

    /**
     * Abandons a rotation before its new key is confirmed - needs no key,
     * so it is also the way out for an admin who lost the session ring.
     */
    public function abbrechen(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->zurueck('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);
        }
        try {
            $alt = $this->rotation->abbrechen((int) $this->session->userId(), $request->ip, new \DateTimeImmutable());
        } catch (RotationException $e) {
            return $this->zurueck($e->getMessage(), FlashArt::Fehler);
        }
        unset($_SESSION[self::FORTSCHRITT]);
        $this->benachrichtigen('Der begonnene Wechsel des Tresor-Schlüssels wurde abgebrochen. Es gilt weiter der bisherige Schlüssel.');
        $this->session->flash('Die Schlüsselrotation ist abgebrochen. Es gilt weiter der bisherige Schlüssel.', FlashArt::Ok);

        $antwort = Response::redirect('/admin/tresor/rotation');
        $ring = $this->ring($request);

        return $ring !== null && $ring->canOpen($alt)
            ? $antwort->withCookie(Cookie::vaultKey($this->sessionVault->store($ring->generation($alt)), Request::httpsFromGlobals()))
            : $antwort;
    }

    public function abschliessen(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->zurueck('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);
        }
        $ring = $this->ring($request);
        if ($ring === null) {
            return $this->zurueck('Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte neu anmelden.', FlashArt::Fehler);
        }

        $userId = (int) $this->session->userId();
        try {
            $ergebnis = $this->rotation->abschliessen($ring, $userId, $request->ip, new \DateTimeImmutable());
        } catch (RotationException $e) {
            return $this->zurueck($e->getMessage(), FlashArt::Fehler);
        }
        unset($_SESSION[self::FORTSCHRITT]);

        // Every session ended with the epoch - this one carries on with the
        // new key alone.
        $this->session->adoptEpoch($this->users->findById($userId)?->sessionEpoch ?? 0);
        $this->benachrichtigen('Der Tresor-Schlüssel des Vereins wurde gewechselt. Alle Sitzungen wurden beendet.');

        $unlesbar = array_sum($ergebnis['defekt']);
        $this->session->flash(
            $unlesbar === 0
                ? 'Die Schlüsselrotation ist abgeschlossen. Alle anderen Sitzungen wurden beendet.'
                : sprintf(
                    'Die Schlüsselrotation ist abgeschlossen. %d Datensätze waren schon vorher nicht lesbar und bleiben es.',
                    $unlesbar,
                ),
            $unlesbar === 0 ? FlashArt::Ok : FlashArt::Warnung,
        );

        return Response::redirect('/admin/tresor/rotation')->withCookie(
            Cookie::vaultKey($this->sessionVault->store($ergebnis['tresor']), Request::httpsFromGlobals()),
        );
    }

    private function ring(Request $request): ?Vault
    {
        try {
            return $this->sessionVault->unlock(Cookie::vaultKeyFrom($request));
        } catch (CryptoException) {
            return null;
        }
    }

    /**
     * @return array{cursor?: array<string, int>, defekt?: int, durchlaufen?: bool}
     */
    private function fortschritt(): array
    {
        $wert = $_SESSION[self::FORTSCHRITT] ?? [];
        if (!is_array($wert)) {
            return [];
        }
        $cursor = [];
        foreach (is_array($wert['cursor'] ?? null) ? $wert['cursor'] : [] as $tabelle => $id) {
            if (is_string($tabelle) && is_int($id)) {
                $cursor[$tabelle] = $id;
            }
        }

        return [
            'cursor' => $cursor,
            'defekt' => is_int($wert['defekt'] ?? null) ? $wert['defekt'] : 0,
            'durchlaufen' => ($wert['durchlaufen'] ?? false) === true,
        ];
    }

    private function zurueck(string $meldung, FlashArt $art): ResponseInterface
    {
        $this->session->flash($meldung, $art);

        return Response::redirect('/admin/tresor/rotation');
    }

    /**
     * Security notice to every admin who can grant the vault - a rotation
     * nobody expected must stand out.
     */
    private function benachrichtigen(string $ereignis): void
    {
        foreach ($this->verwaltung->freigeber() as $admin) {
            $email = $this->crypto->decryptOrNull($admin->emailEnc);
            if ($email === null) {
                continue;
            }
            $this->mailer->sendeSicherheitshinweis($email, $ereignis);
        }
    }
}
