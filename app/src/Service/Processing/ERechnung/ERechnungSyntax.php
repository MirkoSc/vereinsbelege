<?php

declare(strict_types=1);

namespace App\Service\Processing\ERechnung;

/**
 * The two syntaxes EN 16931 allows and the German e-invoice formats use
 * (docs/spec/03-erfassung-und-ki.md section 3, issue #46/M7-4):
 * UN/CEFACT Cross Industry Invoice - ZUGFeRD 2.x, Factur-X and XRechnung
 * in CII syntax - and OASIS UBL 2.1 Invoice/CreditNote - XRechnung in UBL
 * syntax.
 */
enum ERechnungSyntax: string
{
    case Cii = 'cii';
    case Ubl = 'ubl';

    public const string NS_CII = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
    public const string NS_UBL_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    public const string NS_UBL_CREDIT_NOTE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';

    /** ZUGFeRD 1.0 - a different, pre-EN-16931 schema this reader does not follow. */
    public const string NS_ZUGFERD_1 = 'urn:ferd:CrossIndustryDocument:invoice:1p0';

    /**
     * The syntax of a document by its root element, null for anything else
     * (ZUGFeRD 1.0 included - see ERechnungBefund::NichtUnterstuetzt).
     */
    public static function ausWurzel(string $namespace, string $name): ?self
    {
        return match (true) {
            $namespace === self::NS_CII && $name === 'CrossIndustryInvoice' => self::Cii,
            $namespace === self::NS_UBL_INVOICE && $name === 'Invoice',
            $namespace === self::NS_UBL_CREDIT_NOTE && $name === 'CreditNote' => self::Ubl,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Cii => 'CII (ZUGFeRD/Factur-X/XRechnung)',
            self::Ubl => 'UBL (XRechnung)',
        };
    }
}
