<?php

/**
 * Start page of the user area. Everything behind it arrives milestone by
 * milestone; the inbox is the first (issue #27/M4-5), the internal capture
 * the second (issue #28/M4-6).
 *
 * @var bool $posteingang whether the account may open the inbox
 * @var bool $erfassen whether the account may capture receipts
 */
?>
<section class="schmal">
    <h2>Belegverwaltung</h2>
    <p>
        Die Fachbereiche aus der Navigation füllen sich mit den folgenden Meilensteinen.
    </p>
    <?php if (($posteingang ?? false) || ($erfassen ?? false)): ?>
        <p class="knopfreihe">
            <?php if ($posteingang ?? false): ?>
                <a class="knopf knopf-primaer" href="/app/posteingang">Zum Posteingang</a>
            <?php endif; ?>
            <?php if ($erfassen ?? false): ?>
                <a class="knopf" href="/app/belege/neu">Belege erfassen</a>
            <?php endif; ?>
        </p>
    <?php else: ?>
        <div class="leer">Noch keine Belege.</div>
    <?php endif; ?>
</section>
