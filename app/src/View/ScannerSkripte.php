<?php

declare(strict_types=1);

namespace App\View;

/**
 * The scanner's scripts in their load order (docs/spec/03-erfassung-und-ki.md
 * section 2): the math (homographie/entzerrung/schwelle) before the edge
 * detection (kanten) before the corner editor (eckeditor), then the glue
 * the capture pages share (scanner: editor dialog and automatic processing,
 * kamera: live camera; issue #34/M5-4). Classic scripts sharing one scope
 * (CLAUDE.md section 4), so every page that uses them lists them in exactly
 * this order, ahead of its own page script.
 */
final class ScannerSkripte
{
    public const array LISTE = [
        '/js/scanner/homographie.js',
        '/js/scanner/entzerrung.js',
        '/js/scanner/schwelle.js',
        '/js/scanner/kanten.js',
        '/js/scanner/eckeditor.js',
        '/js/scanner/scanner.js',
        '/js/scanner/kamera.js',
    ];
}
