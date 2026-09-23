<?php

/**
 * Invite a user, or change one (issue #20/M3-7, docs/spec/01-sicherheit.md
 * sections 2 and 4).
 *
 * Only components from /admin/designsystem, no JavaScript. Roles are
 * checkboxes; the rules (external roles alone, end date for external
 * accounts) are App\Service\Account\AccessAssignment's and come back as a
 * message if broken. Lock/unlock and "Einladung erneut senden" are separate
 * forms below, each its own POST with its own CSRF token.
 *
 * @var string $csrf
 * @var \App\Domain\User|null $user null = invite
 * @var array{name: string, email: string, rollen: list<int>, kostenstellen: list<int>, von: string, bis: string, ablauf: string} $eingabe
 * @var string|null $fehler
 * @var list<\App\Domain\Role> $alleRollen
 * @var array<int, string> $alleKostenstellen
 * @var array{0: string, 1: string}|null $status
 * @var array{0: string, 1: string}|null $tresor
 * @var bool $eigenesKonto
 * @var int $standardTage
 */

$neu = $user === null;
$ziel = $neu ? '/admin/benutzer' : '/admin/benutzer/' . $user->id;
?>
<section class="schmal">
    <h2><?= e($neu ? 'Benutzer einladen' : 'Benutzer „' . $eingabe['name'] . '“') ?></h2>

    <p><a href="/admin/benutzer">← Alle Benutzer</a></p>

    <?php if ($fehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert"><?= e($fehler) ?></p>
    <?php endif; ?>

    <?php if (!$neu && $status !== null && $tresor !== null): ?>
        <p>
            <span class="marke <?= e($status[1]) ?>"><?= e($status[0]) ?></span>
            <span class="marke <?= e($tresor[1]) ?>"><?= e($tresor[0]) ?></span>
        </p>
    <?php endif; ?>

    <form method="post" action="<?= e($ziel) ?>" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <label for="benutzer-name">Name <span class="pflicht" aria-hidden="true">*</span>
            <input type="text" id="benutzer-name" name="name" value="<?= e($eingabe['name']) ?>"
                   maxlength="<?= e((string) \App\Service\Account\Invitation::NAME_MAX) ?>" autocomplete="off" required>
        </label>

        <?php if ($neu): ?>
            <label for="benutzer-email">E-Mail-Adresse <span class="pflicht" aria-hidden="true">*</span>
                <input type="email" id="benutzer-email" name="email" value="<?= e($eingabe['email']) ?>"
                       autocomplete="off" required>
            </label>
            <p class="feld-hilfe">An diese Adresse geht der Einladungslink (72 Stunden gültig).</p>
        <?php else: ?>
            <p><strong>E-Mail-Adresse:</strong> <?= e($eingabe['email']) ?></p>
        <?php endif; ?>

        <fieldset>
            <legend>Rollen <span class="pflicht" aria-hidden="true">*</span></legend>
            <?php foreach ($alleRollen as $rolle): ?>
                <label class="feld-ankreuz">
                    <input type="checkbox" name="rolle[]" value="<?= e((string) $rolle->id) ?>"
                           <?= in_array($rolle->id, $eingabe['rollen'], true) ? 'checked' : '' ?>>
                    <span><?= e($rolle->name) ?><?= $rolle->extern ? ' <span class="marke marke-warnung">Extern</span>' : '' ?></span>
                </label>
            <?php endforeach; ?>
            <p class="feld-hilfe">
                Mehrere Rollen: Das Konto darf, was eine davon erlaubt. Externe Rollen (Kassenprüfer,
                Steuerberater) lassen sich nicht mit internen kombinieren.
            </p>
        </fieldset>

        <?php if ($alleKostenstellen !== []): ?>
            <fieldset>
                <legend>Kostenstellen</legend>
                <?php foreach ($alleKostenstellen as $id => $name): ?>
                    <label class="feld-ankreuz">
                        <input type="checkbox" name="kostenstelle[]" value="<?= e((string) $id) ?>"
                               <?= in_array($id, $eingabe['kostenstellen'], true) ? 'checked' : '' ?>>
                        <span><?= e($name) ?></span>
                    </label>
                <?php endforeach; ?>
                <p class="feld-hilfe">Gilt nur für Rechte, die eine Rolle auf „eigene Kostenstellen“ beschränkt.</p>
            </fieldset>
        <?php endif; ?>

        <fieldset>
            <legend>Zeitraum der Daten (optional)</legend>
            <label for="benutzer-von" class="feld-kurz">Von
                <input type="date" id="benutzer-von" name="von" value="<?= e($eingabe['von']) ?>">
            </label>
            <label for="benutzer-bis" class="feld-kurz">Bis
                <input type="date" id="benutzer-bis" name="bis" value="<?= e($eingabe['bis']) ?>">
            </label>
            <p class="feld-hilfe">Beschränkt, welche Belege und Buchungen das Konto sieht – z. B. nur ein Geschäftsjahr.</p>
        </fieldset>

        <label for="benutzer-ablauf" class="feld-kurz">Zugang bis (einschließlich)
            <input type="date" id="benutzer-ablauf" name="ablauf" value="<?= e($eingabe['ablauf']) ?>"
                   aria-describedby="benutzer-ablauf-hilfe">
        </label>
        <p class="feld-hilfe" id="benutzer-ablauf-hilfe">
            Leer lassen für unbefristet. Externe Zugänge brauchen ein Ablaufdatum – ohne Angabe gilt heute
            + <?= e((string) $standardTage) ?> Tage.
        </p>

        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-primaer"><?= $neu ? 'Einladung senden' : 'Speichern' ?></button>
            <a class="knopf" href="/admin/benutzer">Abbrechen</a>
        </p>
    </form>

    <?php if (!$neu): ?>
        <?php if ($user->status === \App\Domain\UserStatus::Eingeladen): ?>
            <h3>Einladung</h3>
            <form method="post" action="/admin/benutzer/<?= e((string) $user->id) ?>/einladung-erneut" class="formular">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <p class="gedaempft">Ein neuer Link ersetzt den bisherigen.</p>
                <p class="knopfreihe"><button type="submit" class="knopf">Einladung erneut senden</button></p>
            </form>
        <?php endif; ?>

        <h3>Sperren</h3>
        <?php if ($eigenesKonto): ?>
            <p class="gedaempft">Das eigene Konto lässt sich nicht sperren.</p>
        <?php elseif ($user->status === \App\Domain\UserStatus::Gesperrt): ?>
            <form method="post" action="/admin/benutzer/<?= e((string) $user->id) ?>/entsperren" class="formular">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <p class="gedaempft">Nach dem Entsperren braucht das Konto eine neue Tresor-Freigabe.</p>
                <p class="knopfreihe"><button type="submit" class="knopf">Konto entsperren</button></p>
            </form>
        <?php else: ?>
            <form method="post" action="/admin/benutzer/<?= e((string) $user->id) ?>/sperren" class="formular">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <p class="gedaempft">
                    Sperren beendet sofort alle Sitzungen und entzieht die Tresor-Freigabe. Nach einem
                    Entsperren muss der Tresor erneut freigegeben werden.
                </p>
                <p class="knopfreihe"><button type="submit" class="knopf knopf-gefahr">Konto sperren</button></p>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
