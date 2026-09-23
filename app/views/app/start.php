<?php

/**
 * Start page of the user area. Everything behind it arrives milestone by
 * milestone; the inbox is the first (issue #27/M4-5).
 *
 * @var bool $posteingang whether the account may open the inbox
 */
?>
<section class="schmal">
    <h2>Belegverwaltung</h2>
    <p>
        Die Fachbereiche aus der Navigation füllen sich mit den folgenden Meilensteinen.
    </p>
    <?php if ($posteingang ?? false): ?>
        <p class="knopfreihe">
            <a class="knopf knopf-primaer" href="/app/posteingang">Zum Posteingang</a>
        </p>
    <?php else: ?>
        <div class="leer">Noch keine Belege.</div>
    <?php endif; ?>
</section>
