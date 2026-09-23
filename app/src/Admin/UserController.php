<?php

declare(strict_types=1);

namespace App\Admin;

use App\Domain\Role;
use App\Domain\User;
use App\Domain\UserStatus;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\AuthTokenRepository;
use App\Repository\CostCenterRepository;
use App\Repository\RoleRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Service\Account\AccessAssignment;
use App\Service\Account\Invitation;
use App\Service\Account\RoleRuleViolation;
use App\Service\Account\UserAdministration;
use App\Service\Account\UserRuleViolation;
use App\Service\Crypto\ServerCrypto;
use App\Service\Mail\FreigabeBenachrichtigung;
use App\Service\Mail\Mailer;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\PublicUrl;
use App\Support\FileLogger;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * User management (issue #20/M3-7, docs/spec/01-sicherheit.md sections 2
 * and 4): the list, inviting, changing roles/scopes/end date, locking and
 * unlocking, sending an invitation again.
 *
 * Behind `admin.users` (app/src/routes.php). Addresses and names are
 * operating data under the server key - decrypted here for the page, never
 * logged, never in a URL. The vault grant itself is the Tresor page's
 * (App\Admin\VaultGrantController, `admin.vault_grant`); this page only
 * shows its state.
 *
 * Form format: `name`, `email` (new only), `rolle[]` role ids,
 * `kostenstelle[]` cost-center ids, `von`/`bis` period (Y-m-d), `ablauf`
 * last day of access (Y-m-d).
 */
final readonly class UserController
{
    public function __construct(
        private View $view,
        private Session $session,
        private UserRepository $users,
        private RoleRepository $rollen,
        private UserAccessRepository $zugriff,
        private AuthTokenRepository $tokens,
        private CostCenterRepository $kostenstellen,
        private Invitation $einladung,
        private UserAdministration $verwaltung,
        private AccessAssignment $zuweisung,
        private Mailer $mailer,
        private MailSettingsRepository $mailSettings,
        private ServerCrypto $crypto,
        private FreigabeBenachrichtigung $freigabeHinweis,
        private ?FileLogger $logger = null,
    ) {
    }

    public function liste(Request $request): ResponseInterface
    {
        $this->session->start();
        $jetzt = new \DateTimeImmutable();
        $ausstehend = array_flip($this->verwaltung->ausstehend($jetzt));

        $zeilen = [];
        foreach ($this->users->all() as $user) {
            $zeilen[] = [
                'id' => $user->id,
                'name' => $this->crypto->decrypt($user->displayNameEnc),
                'email' => $this->crypto->decrypt($user->emailEnc),
                'rollen' => array_map(static fn(Role $r): string => $r->name, $this->rollen->forUser($user->id)),
                'status' => self::status($user, $jetzt, $this->tokens->hasUsable($user->id, Invitation::TYP, $jetzt)),
                'tresor' => self::tresor($user, isset($ausstehend[$user->id]), $jetzt),
                'ablauf' => $user->expiresAt,
                'letzteAnmeldung' => $user->lastLoginAt,
            ];
        }
        usort($zeilen, static fn(array $a, array $b): int => strcmp(mb_strtolower($a['name']), mb_strtolower($b['name'])));

        return Response::html($this->view->render('admin/benutzer', [
            'title' => 'Benutzer',
            'flash' => $this->session->pullFlash(),
            'zeilen' => $zeilen,
            'eigeneId' => $this->session->userId(),
        ], Area::Admin));
    }

    public function neu(Request $request): ResponseInterface
    {
        $this->session->start();

        return $this->formular(null, self::leereEingabe());
    }

    public function anlegen(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/admin/benutzer/neu');
        }

        $eingabe = self::eingabeAus($request);
        try {
            [$von, $bis, $ablauf] = self::daten($eingabe);
            $ergebnis = $this->einladung->invite(
                $eingabe['email'],
                $eingabe['name'],
                $eingabe['rollen'],
                $eingabe['kostenstellen'],
                $von,
                $bis,
                $ablauf,
            );
        } catch (UserRuleViolation | RoleRuleViolation $e) {
            return $this->formular(null, $eingabe, $e->getMessage(), 422);
        }

        $this->einladungSenden($request, $eingabe['email'], $ergebnis['token'], 'Einladung an „' . trim($eingabe['name']) . '“');

        return Response::redirect('/admin/benutzer/' . $ergebnis['userId']);
    }

    /**
     * @param array<string, string> $params
     */
    public function bearbeiten(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $user = $this->users->findById((int) $params['id']);
        if ($user === null) {
            return $this->nichtGefunden();
        }

        $scope = $this->zugriff->scope($user->id);

        return $this->formular($user, [
            'name' => $this->crypto->decrypt($user->displayNameEnc),
            'email' => $this->crypto->decrypt($user->emailEnc),
            'rollen' => array_map(static fn(Role $r): int => $r->id, $this->rollen->forUser($user->id)),
            'kostenstellen' => $this->zugriff->costCenters($user->id),
            'von' => $scope['von']?->format('Y-m-d') ?? '',
            'bis' => $scope['bis']?->format('Y-m-d') ?? '',
            'ablauf' => $user->expiresAt?->format('Y-m-d') ?? '',
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function speichern(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/admin/benutzer/' . $id);
        }
        $user = $this->users->findById($id);
        if ($user === null) {
            return $this->nichtGefunden();
        }

        $eingabe = [...self::eingabeAus($request), 'email' => $this->crypto->decrypt($user->emailEnc)];
        try {
            [$von, $bis, $ablauf] = self::daten($eingabe);
            if ($eingabe['rollen'] === []) {
                throw new UserRuleViolation('Bitte mindestens eine Rolle wählen.');
            }
            $name = Invitation::pruefeName($eingabe['name']);
            $this->zuweisung->zuweisen($id, $eingabe['rollen'], $eingabe['kostenstellen'], $von, $bis, $ablauf);
            $this->verwaltung->umbenennen($id, $name);
        } catch (UserRuleViolation | RoleRuleViolation $e) {
            return $this->formular($user, $eingabe, $e->getMessage(), 422);
        }

        $this->session->flash(sprintf('Benutzer „%s“ gespeichert.', $name));

        return Response::redirect('/admin/benutzer/' . $id);
    }

    /**
     * @param array<string, string> $params
     */
    public function sperren(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/admin/benutzer/' . $id);
        }

        $user = $this->users->findById($id);
        try {
            $this->verwaltung->sperren($id, (int) $this->session->userId());
        } catch (UserRuleViolation $e) {
            $this->session->flash($e->getMessage(), FlashArt::Fehler);

            return Response::redirect($user === null ? '/admin/benutzer' : '/admin/benutzer/' . $id);
        }

        if ($user?->status === UserStatus::Aktiv) {
            $this->mailer->sendeSicherheitshinweis(
                $this->crypto->decrypt($user->emailEnc),
                'Ihr Zugang wurde von der Vereinsverwaltung gesperrt. Die Freigabe für den Tresor ist damit entzogen.',
            );
        }
        $this->session->flash('Konto gesperrt. Alle Sitzungen sind beendet, die Tresor-Freigabe ist entzogen.');

        return Response::redirect('/admin/benutzer/' . $id);
    }

    /**
     * @param array<string, string> $params
     */
    public function entsperren(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/admin/benutzer/' . $id);
        }

        try {
            $this->verwaltung->entsperren($id);
        } catch (UserRuleViolation $e) {
            $this->session->flash($e->getMessage(), FlashArt::Fehler);

            return Response::redirect('/admin/benutzer/' . $id);
        }

        if (in_array($id, $this->verwaltung->ausstehend(), true)) {
            $this->freigabeHinweis->senden($this->basis($request));
            $this->session->flash('Konto entsperrt. Belege sieht es erst wieder nach einer erneuten Tresor-Freigabe.');
        } else {
            $this->session->flash('Konto entsperrt.');
        }

        return Response::redirect('/admin/benutzer/' . $id);
    }

    /**
     * @param array<string, string> $params
     */
    public function einladungErneut(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/admin/benutzer/' . $id);
        }

        $user = $this->users->findById($id);
        try {
            $token = $this->einladung->erneutSenden($id);
        } catch (UserRuleViolation $e) {
            $this->session->flash($e->getMessage(), FlashArt::Fehler);

            return Response::redirect('/admin/benutzer/' . $id);
        }
        assert($user !== null);

        $this->einladungSenden($request, $this->crypto->decrypt($user->emailEnc), $token, 'Neue Einladung');

        return Response::redirect('/admin/benutzer/' . $id);
    }

    /**
     * Sends the link and leaves a flash that says honestly whether it went
     * out. A failed attempt stays in the queue - the cron retries it.
     */
    private function einladungSenden(Request $request, string $email, #[\SensitiveParameter] string $token, string $was): void
    {
        $basis = $this->basis($request);
        if ($basis === null) {
            // Same rule as the reset link: no trustworthy base, no mail.
            // The log line names the reason, never the address or the token.
            $this->logger?->append('invitation: no public URL configured and request host does not match the sender domain - no mail sent');
            $this->session->flash(
                $was . ' angelegt, aber keine Mail versendet: In den Mail-Einstellungen fehlt die öffentliche Adresse der Installation.',
                FlashArt::Warnung,
            );

            return;
        }

        $versand = $this->mailer->sendeEinladung(
            $email,
            $basis . '/anmelden/einladung?token=' . $token,
            intdiv(Invitation::TTL_SECONDS, 3600),
        );
        if ($versand->erfolg) {
            $this->session->flash($was . ' versendet.');
        } else {
            $this->session->flash(
                $was . ' angelegt, die Mail ist aber noch nicht raus (' . (string) $versand->fehlermeldung . '). Sie wird automatisch erneut versucht.',
                FlashArt::Warnung,
            );
        }
    }

    private function basis(Request $request): ?string
    {
        return PublicUrl::resolve($this->mailSettings->get(), $request->header('host') ?? '', Request::httpsFromGlobals());
    }

    /**
     * @param array{name: string, email: string, rollen: list<int>, kostenstellen: list<int>, von: string, bis: string, ablauf: string} $eingabe
     */
    private function formular(?User $user, array $eingabe, ?string $fehler = null, int $status = 200): ResponseInterface
    {
        $jetzt = new \DateTimeImmutable();

        return Response::html($this->view->render('admin/benutzer-form', [
            'title' => $user === null ? 'Benutzer einladen' : 'Benutzer ' . $eingabe['name'],
            'user' => $user,
            'eingabe' => $eingabe,
            'fehler' => $fehler,
            'flash' => $this->session->pullFlash(),
            'alleRollen' => $this->rollen->all(),
            'alleKostenstellen' => $this->kostenstellen->active(),
            'status' => $user === null ? null : self::status($user, $jetzt, $this->tokens->hasUsable($user->id, Invitation::TYP, $jetzt)),
            'tresor' => $user === null ? null : self::tresor($user, in_array($user->id, $this->verwaltung->ausstehend($jetzt), true), $jetzt),
            'eigenesKonto' => $user !== null && $user->id === $this->session->userId(),
            'standardTage' => AccessAssignment::EXTERN_STANDARD_TAGE,
        ], Area::Admin), $status);
    }

    /**
     * @return array{0: string, 1: string} label, css modifier of `.marke`
     */
    private static function status(User $user, \DateTimeImmutable $jetzt, bool $einladungGueltig): array
    {
        return match (true) {
            $user->status === UserStatus::Gesperrt => ['Gesperrt', 'marke-fehler'],
            $user->expiresAt !== null && $user->expiresAt <= $jetzt => ['Abgelaufen', 'marke-fehler'],
            $user->status === UserStatus::Eingeladen => $einladungGueltig
                ? ['Eingeladen', 'marke-warnung']
                : ['Einladung abgelaufen', 'marke-warnung'],
            default => ['Aktiv', 'marke-ok'],
        };
    }

    /**
     * @return array{0: string, 1: string} label, css modifier of `.marke`
     */
    private static function tresor(User $user, bool $ausstehend, \DateTimeImmutable $jetzt): array
    {
        if ($ausstehend) {
            return ['Freigabe ausstehend', 'marke-warnung'];
        }

        return $user->mayLogIn($jetzt) && $user->status === UserStatus::Aktiv
            ? ['Freigegeben', 'marke-ok']
            : ['Keine Freigabe', ''];
    }

    /**
     * @return array{name: string, email: string, rollen: list<int>, kostenstellen: list<int>, von: string, bis: string, ablauf: string}
     */
    private static function leereEingabe(): array
    {
        return ['name' => '', 'email' => '', 'rollen' => [], 'kostenstellen' => [], 'von' => '', 'bis' => '', 'ablauf' => ''];
    }

    /**
     * @return array{name: string, email: string, rollen: list<int>, kostenstellen: list<int>, von: string, bis: string, ablauf: string}
     */
    private static function eingabeAus(Request $request): array
    {
        return [
            'name' => self::text($request, 'name'),
            'email' => self::text($request, 'email'),
            'rollen' => self::ids($request, 'rolle'),
            'kostenstellen' => self::ids($request, 'kostenstelle'),
            'von' => self::text($request, 'von'),
            'bis' => self::text($request, 'bis'),
            'ablauf' => self::text($request, 'ablauf'),
        ];
    }

    /**
     * The three dates of the form. The end date is the LAST day of access:
     * stored as that day's 23:59:59, because `expires_at` is compared as a
     * moment (User::mayLogIn()).
     *
     * @param array{von: string, bis: string, ablauf: string} $eingabe
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable, 2: ?\DateTimeImmutable}
     * @throws UserRuleViolation
     */
    private static function daten(array $eingabe): array
    {
        $ablauf = self::datum($eingabe['ablauf']);

        return [
            self::datum($eingabe['von']),
            self::datum($eingabe['bis']),
            $ablauf?->setTime(23, 59, 59),
        ];
    }

    /**
     * @throws UserRuleViolation
     */
    private static function datum(string $wert): ?\DateTimeImmutable
    {
        if ($wert === '') {
            return null;
        }
        $datum = \DateTimeImmutable::createFromFormat('!Y-m-d', $wert);
        if ($datum === false || $datum->format('Y-m-d') !== $wert) {
            throw new UserRuleViolation('Bitte ein gültiges Datum angeben.');
        }

        return $datum;
    }

    /**
     * @return list<int>
     */
    private static function ids(Request $request, string $feld): array
    {
        $werte = $request->post[$feld] ?? [];
        if (!is_array($werte)) {
            return [];
        }

        $ids = [];
        foreach ($werte as $wert) {
            if (is_string($wert) && ctype_digit($wert) && (int) $wert > 0) {
                $ids[] = (int) $wert;
            }
        }

        return array_values(array_unique($ids));
    }

    private static function text(Request $request, string $feld): string
    {
        $wert = $request->post[$feld] ?? '';

        return is_string($wert) ? trim($wert) : '';
    }

    private function nichtGefunden(): ResponseInterface
    {
        return Response::html($this->view->render('error', [
            'title' => 'Benutzer nicht gefunden',
            'message' => 'Diesen Benutzer gibt es nicht (mehr).',
            'startseite' => '/admin/benutzer',
        ], Area::Admin), 404);
    }

    private function csrfFailure(string $ziel): ResponseInterface
    {
        $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

        return Response::redirect($ziel);
    }
}
