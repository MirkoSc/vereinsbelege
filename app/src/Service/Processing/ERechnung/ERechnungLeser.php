<?php

declare(strict_types=1);

namespace App\Service\Processing\ERechnung;

use App\Domain\InvoiceType;
use App\Service\Processing\Betrag;

/**
 * Reads a structured e-invoice from its XML (docs/spec/03-erfassung-und-ki.md
 * section 3, issue #46/M7-4): CII - ZUGFeRD 2.x, Factur-X, XRechnung in CII
 * syntax - and UBL 2.1 Invoice/CreditNote - XRechnung in UBL syntax. Only the
 * fields the review page and the supplier lookup need, from the paths
 * EN 16931 fixes for them; no third-party library (why not
 * horstoeko/zugferd: same section of the spec), no AI.
 *
 * Framework-free like everything in App\Service\Processing
 * (CLAUDE.md section 6a), so the optional worker can run it unchanged.
 *
 * Safe against hostile XML: a document with a DOCTYPE is refused before
 * libxml sees it (no entity expansion, no external entities - invoices
 * never need either), the network is off (LIBXML_NONET), the size is
 * capped. Never throws and never lets libxml warn for what is in the file:
 * the befund says why nothing was read (ERechnungBefund).
 *
 * Never guesses: without invoice number, issue date, currency and gross
 * amount there is no e-invoice to take over, only a `Defekt` one.
 */
final class ERechnungLeser
{
    /** The upload limit (App\Service\Upload\UploadService::MAX_FILE_BYTES): an XRechnung may carry its PDF rendering as base64. */
    public const int MAX_BYTES = 32 * 1024 * 1024;

    /** Characters kept of a single text field - an invoice number or a name, never a paragraph. */
    private const int MAX_FELD = 500;

    private const array NAMESPACES = [
        'rsm' => ERechnungSyntax::NS_CII,
        'ram' => 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100',
        'udt' => 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100',
        'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
        'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
    ];

    /** UNTDID 1001: the document type codes of a credit note. */
    private const array GUTSCHRIFT_CODES = ['381', '396', '532'];

    /** UNTDID 4461 payment means → the `payment.method` of `extract-v1`. */
    private const array ZAHLARTEN = [
        '10' => 'bar',
        '30' => 'ueberweisung',
        '31' => 'ueberweisung',
        '42' => 'ueberweisung',
        '58' => 'ueberweisung',
        '49' => 'lastschrift',
        '59' => 'lastschrift',
        '48' => 'karte',
        '54' => 'karte',
        '55' => 'karte',
    ];

    /** @var list<string> */
    private array $warnungen = [];

    private function __construct(private readonly \DOMXPath $xpath)
    {
    }

    /**
     * The syntax of the e-invoice these bytes start with, or null when they
     * are none - for the upload check (App\Service\Upload\MagicBytes), which
     * sees only the first bytes of a file: a pull parser reads up to the
     * root element and stops there, so a truncated file is no obstacle.
     * Anything with a DOCTYPE is refused like in lesen().
     */
    public static function wurzel(string $anfang): ?ERechnungSyntax
    {
        if (trim($anfang) === '' || self::hatDoctype($anfang)) {
            return null;
        }

        $vorher = libxml_use_internal_errors(true);
        $reader = new \XMLReader();
        try {
            if (!$reader->XML($anfang, null, LIBXML_NONET)) {
                return null;
            }
            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::DOC_TYPE) {
                    return null;
                }
                if ($reader->nodeType === \XMLReader::ELEMENT) {
                    return ERechnungSyntax::ausWurzel($reader->namespaceURI, $reader->localName);
                }
            }

            return null;
        } catch (\ValueError) {
            return null;
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($vorher);
        }
    }

    public static function lesen(string $xml): ERechnungErgebnis
    {
        if (strlen($xml) > self::MAX_BYTES) {
            return ERechnungErgebnis::ohne(ERechnungBefund::ZuGross);
        }
        if (trim($xml) === '' || self::hatDoctype($xml)) {
            return ERechnungErgebnis::ohne(ERechnungBefund::Defekt);
        }

        $vorher = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            // PARSEHUGE: a base64 attachment may be a single text node of
            // several MB. Safe here - without a DOCTYPE there is nothing to
            // expand.
            if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE) || $dom->documentElement === null) {
                return ERechnungErgebnis::ohne(ERechnungBefund::Defekt);
            }

            $wurzel = $dom->documentElement;
            if ($wurzel->namespaceURI === ERechnungSyntax::NS_ZUGFERD_1) {
                return ERechnungErgebnis::ohne(ERechnungBefund::NichtUnterstuetzt);
            }
            $syntax = ERechnungSyntax::ausWurzel((string) $wurzel->namespaceURI, $wurzel->localName ?? '');
            if ($syntax === null) {
                return ERechnungErgebnis::ohne(ERechnungBefund::Keine);
            }

            $xpath = new \DOMXPath($dom);
            foreach (self::NAMESPACES as $prefix => $uri) {
                $xpath->registerNamespace($prefix, $uri);
            }
            $leser = new self($xpath);
            $rechnung = match ($syntax) {
                ERechnungSyntax::Cii => $leser->cii(),
                ERechnungSyntax::Ubl => $leser->ubl($wurzel->localName === 'CreditNote'),
            };

            return $rechnung === null
                ? ERechnungErgebnis::ohne(ERechnungBefund::Defekt)
                : new ERechnungErgebnis(ERechnungBefund::Gelesen, $rechnung);
        } catch (\ValueError | \TypeError | \ArithmeticError) {
            return ERechnungErgebnis::ohne(ERechnungBefund::Defekt);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($vorher);
        }
    }

    /**
     * Cents from an XML decimal ("119.00", "-5.5", "12"): a point, never a
     * comma, no thousands separator. More than two decimals are rounded half
     * away from zero, with a warning - EN 16931 allows it for totals only in
     * theory, but nothing below a cent is booked.
     */
    public static function cent(string $text, ?bool &$gerundet = null): ?int
    {
        $gerundet = false;
        if (preg_match('/^\s*([+-]?)(\d{1,12})(?:\.(\d{1,10}))?\s*$/', $text, $teile) !== 1) {
            return null;
        }
        $bruch = rtrim($teile[3] ?? '', '0');
        $cent = (int) $teile[2] * 100 + (int) str_pad(substr($bruch, 0, 2), 2, '0');
        if (strlen($bruch) > 2) {
            $gerundet = true;
            if ((int) $bruch[2] >= 5) {
                $cent++;
            }
        }
        if ($cent > Betrag::MAX_CENT) {
            return null;
        }

        return $teile[1] === '-' ? -$cent : $cent;
    }

    private function cii(): ?ERechnung
    {
        $kopf = '/rsm:CrossIndustryInvoice';
        $handel = $kopf . '/rsm:SupplyChainTradeTransaction';
        $vereinbarung = $handel . '/ram:ApplicableHeaderTradeAgreement';
        $verkaeufer = $vereinbarung . '/ram:SellerTradeParty';
        $abrechnung = $handel . '/ram:ApplicableHeaderTradeSettlement';
        $zahlung = $abrechnung . '/ram:SpecifiedTradeSettlementPaymentMeans[1]';
        $bedingungen = $abrechnung . '/ram:SpecifiedTradePaymentTerms[1]';
        $summen = $abrechnung . '/ram:SpecifiedTradeSettlementHeaderMonetarySummation';

        $nummer = $this->text($kopf . '/rsm:ExchangedDocument/ram:ID');
        $datum = $this->ciiDatum($kopf . '/rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString');
        $waehrung = $this->text($abrechnung . '/ram:InvoiceCurrencyCode');
        $brutto = $this->betrag($summen . '/ram:GrandTotalAmount');
        if ($nummer === '' || $datum === null || preg_match('/^[A-Z]{3}$/', $waehrung) !== 1 || $brutto === null) {
            return null;
        }

        $von = $this->ciiDatum($abrechnung . '/ram:BillingSpecifiedPeriod/ram:StartDateTime/udt:DateTimeString');
        $bis = $this->ciiDatum($abrechnung . '/ram:BillingSpecifiedPeriod/ram:EndDateTime/udt:DateTimeString');
        if ($von === null && $bis === null) {
            // No billing period: the delivery date is the date of service.
            $von = $bis = $this->ciiDatum($handel . '/ram:ApplicableHeaderTradeDelivery/ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString');
        }

        $steuern = [];
        foreach ($this->knoten($abrechnung . '/ram:ApplicableTradeTax') as $steuer) {
            $this->steuerzeile($steuern, $this->text('ram:RateApplicablePercent', $steuer), $this->text('ram:CalculatedAmount', $steuer));
        }

        $email = $this->text($verkaeufer . '/ram:DefinedTradeContact/ram:EmailURIUniversalCommunication/ram:URIID');
        if ($email === '') {
            $email = $this->text($verkaeufer . "/ram:URIUniversalCommunication/ram:URIID[@schemeID='EM']");
        }
        $offen = $this->betrag($summen . '/ram:DuePayableAmount');

        return $this->rechnung(
            syntax: ERechnungSyntax::Cii,
            profil: $this->text($kopf . '/rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID'),
            typ: in_array($this->text($kopf . '/rsm:ExchangedDocument/ram:TypeCode'), self::GUTSCHRIFT_CODES, true) ? InvoiceType::Gutschrift : InvoiceType::Rechnung,
            nummer: $nummer,
            datum: $datum,
            faellig: $this->ciiDatum($bedingungen . '/ram:DueDateDateTime/udt:DateTimeString'),
            von: $von,
            bis: $bis,
            waehrung: $waehrung,
            brutto: $brutto,
            netto: $this->betrag($summen . '/ram:TaxBasisTotalAmount'),
            steuern: $steuern,
            name: $this->text($verkaeufer . '/ram:Name'),
            anschrift: $this->anschrift($verkaeufer . '/ram:PostalTradeAddress', ['ram:LineOne', 'ram:LineTwo', 'ram:LineThree'], 'ram:PostcodeCode', 'ram:CityName', 'ram:CountryID'),
            ustId: $this->text($verkaeufer . "/ram:SpecifiedTaxRegistration/ram:ID[@schemeID='VA']"),
            steuernummer: $this->text($verkaeufer . "/ram:SpecifiedTaxRegistration/ram:ID[@schemeID='FC']"),
            email: $email,
            iban: $this->text($zahlung . '/ram:PayeePartyCreditorFinancialAccount/ram:IBANID'),
            bic: $this->text($zahlung . '/ram:PayeeSpecifiedCreditorFinancialInstitution/ram:BICID'),
            glaeubigerId: $this->text($abrechnung . '/ram:CreditorReferenceID'),
            mandat: $this->text($bedingungen . '/ram:DirectDebitMandateID'),
            kundennummer: $this->text($vereinbarung . '/ram:BuyerTradeParty/ram:ID'),
            vertrag: $this->text($vereinbarung . '/ram:ContractReferencedDocument/ram:IssuerAssignedID'),
            zahlartCode: $this->text($zahlung . '/ram:TypeCode'),
            offen: $offen,
        );
    }

    private function ubl(bool $gutschrift): ?ERechnung
    {
        $partei = '/*/cac:AccountingSupplierParty/cac:Party';
        $zahlung = '/*/cac:PaymentMeans[1]';
        $summen = '/*/cac:LegalMonetaryTotal';

        $nummer = $this->text('/*/cbc:ID');
        $datum = $this->isoDatum('/*/cbc:IssueDate');
        $waehrung = $this->text('/*/cbc:DocumentCurrencyCode');
        $brutto = $this->betrag($summen . '/cbc:TaxInclusiveAmount');
        if ($nummer === '' || $datum === null || preg_match('/^[A-Z]{3}$/', $waehrung) !== 1 || $brutto === null) {
            return null;
        }

        $von = $this->isoDatum('/*/cac:InvoicePeriod/cbc:StartDate');
        $bis = $this->isoDatum('/*/cac:InvoicePeriod/cbc:EndDate');
        if ($von === null && $bis === null) {
            $von = $bis = $this->isoDatum('/*/cac:Delivery/cbc:ActualDeliveryDate');
        }

        $steuern = [];
        // Only the tax total with subtotals: a second one, in the
        // accounting currency, carries the sum alone.
        foreach ($this->knoten('/*/cac:TaxTotal/cac:TaxSubtotal') as $steuer) {
            $this->steuerzeile($steuern, $this->text('cac:TaxCategory/cbc:Percent', $steuer), $this->text('cbc:TaxAmount', $steuer));
        }

        $name = $this->text($partei . '/cac:PartyName/cbc:Name');
        if ($name === '') {
            $name = $this->text($partei . '/cac:PartyLegalEntity/cbc:RegistrationName');
        }
        $email = $this->text($partei . '/cac:Contact/cbc:ElectronicMail');
        if ($email === '') {
            $email = $this->text($partei . "/cbc:EndpointID[@schemeID='EM']");
        }
        $faellig = $this->isoDatum('/*/cbc:DueDate') ?? $this->isoDatum($zahlung . '/cbc:PaymentDueDate');
        $typCode = $this->text('/*/cbc:InvoiceTypeCode') . $this->text('/*/cbc:CreditNoteTypeCode');

        return $this->rechnung(
            syntax: ERechnungSyntax::Ubl,
            profil: $this->text('/*/cbc:CustomizationID'),
            typ: $gutschrift || in_array($typCode, self::GUTSCHRIFT_CODES, true) ? InvoiceType::Gutschrift : InvoiceType::Rechnung,
            nummer: $nummer,
            datum: $datum,
            faellig: $faellig,
            von: $von,
            bis: $bis,
            waehrung: $waehrung,
            brutto: $brutto,
            netto: $this->betrag($summen . '/cbc:TaxExclusiveAmount'),
            steuern: $steuern,
            name: $name,
            anschrift: $this->anschrift($partei . '/cac:PostalAddress', ['cbc:StreetName', 'cbc:AdditionalStreetName', 'cac:AddressLine/cbc:Line'], 'cbc:PostalZone', 'cbc:CityName', 'cac:Country/cbc:IdentificationCode'),
            ustId: $this->text($partei . "/cac:PartyTaxScheme[cac:TaxScheme/cbc:ID='VAT']/cbc:CompanyID"),
            steuernummer: $this->text($partei . "/cac:PartyTaxScheme[not(cac:TaxScheme/cbc:ID='VAT')]/cbc:CompanyID"),
            email: $email,
            iban: $this->text($zahlung . '/cac:PayeeFinancialAccount/cbc:ID'),
            bic: $this->text($zahlung . '/cac:PayeeFinancialAccount/cac:FinancialInstitutionBranch/cbc:ID'),
            glaeubigerId: $this->text($partei . "/cac:PartyIdentification/cbc:ID[@schemeID='SEPA']"),
            mandat: $this->text($zahlung . '/cac:PaymentMandate/cbc:ID'),
            kundennummer: $this->text('/*/cac:AccountingCustomerParty/cac:Party/cac:PartyIdentification/cbc:ID'),
            vertrag: $this->text('/*/cac:ContractDocumentReference/cbc:ID'),
            zahlartCode: $this->text($zahlung . '/cbc:PaymentMeansCode'),
            offen: $this->betrag($summen . '/cbc:PayableAmount'),
        );
    }

    /**
     * @param list<array{rate: string, amount: int}> $steuern
     */
    private function rechnung(
        ERechnungSyntax $syntax,
        string $profil,
        InvoiceType $typ,
        string $nummer,
        string $datum,
        ?string $faellig,
        ?string $von,
        ?string $bis,
        string $waehrung,
        int $brutto,
        ?int $netto,
        array $steuern,
        string $name,
        string $anschrift,
        string $ustId,
        string $steuernummer,
        string $email,
        string $iban,
        string $bic,
        string $glaeubigerId,
        string $mandat,
        string $kundennummer,
        string $vertrag,
        string $zahlartCode,
        ?int $offen,
    ): ERechnung {
        if (!Betrag::summePasst($netto, array_column($steuern, 'amount'), $brutto)) {
            $this->warnung('Netto und Steuern ergeben laut E-Rechnung nicht den Bruttobetrag.');
        }

        return new ERechnung(
            syntax: $syntax,
            profil: $profil,
            typ: $typ,
            nummer: $nummer,
            datum: $datum,
            faellig: $faellig,
            leistungVon: $von,
            leistungBis: $bis,
            waehrung: $waehrung,
            brutto: $brutto,
            netto: $netto,
            steuern: $steuern,
            lieferantName: $name,
            lieferantAnschrift: $anschrift,
            ustId: $ustId,
            steuernummer: $steuernummer,
            email: $email,
            iban: strtoupper((string) preg_replace('/\s+/', '', $iban)),
            bic: strtoupper((string) preg_replace('/\s+/', '', $bic)),
            glaeubigerId: $glaeubigerId,
            mandatsreferenz: $mandat,
            kundennummer: $kundennummer,
            vertragsnummer: $vertrag,
            zahlart: self::ZAHLARTEN[$zahlartCode] ?? 'unbekannt',
            // Nothing left to pay on a non-zero invoice: settled already
            // (paid in advance, by card at the counter).
            bezahlt: $offen === 0 && $brutto !== 0,
            warnungen: $this->warnungen,
        );
    }

    /**
     * @param list<array{rate: string, amount: int}> $steuern
     */
    private function steuerzeile(array &$steuern, string $satz, string $betrag): void
    {
        $cent = $this->centMitWarnung($betrag);
        // "19.00" and "7.000" are what generators write; Betrag::prozent()
        // takes at most two decimals.
        $rate = Betrag::prozent(str_contains($satz, '.') ? rtrim(rtrim($satz, '0'), '.') : $satz);
        if ($cent === null || $rate === null) {
            $this->warnung('Eine Steuerzeile der E-Rechnung ist unvollständig und wurde nicht übernommen.');

            return;
        }
        $steuern[] = ['rate' => $rate, 'amount' => $cent];
    }

    private function betrag(string $pfad): ?int
    {
        $text = $this->text($pfad);

        return $text === '' ? null : $this->centMitWarnung($text);
    }

    private function centMitWarnung(string $text): ?int
    {
        $cent = self::cent($text, $gerundet);
        if ($gerundet === true) {
            $this->warnung('Ein Betrag der E-Rechnung hat mehr als zwei Nachkommastellen und wurde auf Cent gerundet.');
        }

        return $cent;
    }

    /** CII dates: format 102 is YYYYMMDD; the other formats (month, week) are no day. */
    private function ciiDatum(string $pfad): ?string
    {
        $knoten = $this->knoten($pfad)[0] ?? null;
        if (!$knoten instanceof \DOMElement || ($knoten->hasAttribute('format') && $knoten->getAttribute('format') !== '102')) {
            return null;
        }

        return self::datum(trim($knoten->textContent), 'Ymd');
    }

    private function isoDatum(string $pfad): ?string
    {
        return self::datum($this->text($pfad), 'Y-m-d');
    }

    private static function datum(string $text, string $format): ?string
    {
        $datum = \DateTimeImmutable::createFromFormat('!' . $format, $text);

        return $datum !== false && $datum->format($format) === $text ? $datum->format('Y-m-d') : null;
    }

    /**
     * Address lines, then "postcode city", then the country - each part only
     * when present, joined with ", ".
     *
     * @param list<string> $zeilen
     */
    private function anschrift(string $pfad, array $zeilen, string $plz, string $ort, string $land): string
    {
        $knoten = $this->knoten($pfad)[0] ?? null;
        if ($knoten === null) {
            return '';
        }
        $teile = array_map(fn(string $zeile): string => $this->text($zeile, $knoten), $zeilen);
        $teile[] = trim($this->text($plz, $knoten) . ' ' . $this->text($ort, $knoten));
        $teile[] = $this->text($land, $knoten);

        return implode(', ', array_filter($teile, static fn(string $teil): bool => $teil !== ''));
    }

    /** The first node's text, whitespace folded, valid UTF-8, at most MAX_FELD characters; '' when absent. */
    private function text(string $pfad, ?\DOMNode $kontext = null): string
    {
        $knoten = $this->knoten($pfad, $kontext)[0] ?? null;
        if ($knoten === null) {
            return '';
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', mb_scrub($knoten->textContent, 'UTF-8')));

        return mb_substr($text, 0, self::MAX_FELD, 'UTF-8');
    }

    /**
     * @return list<\DOMNode>
     */
    private function knoten(string $pfad, ?\DOMNode $kontext = null): array
    {
        $liste = $this->xpath->query($pfad, $kontext);
        if ($liste === false) {
            return [];
        }
        $knoten = [];
        foreach ($liste as $eintrag) {
            $knoten[] = $eintrag;
        }

        return $knoten;
    }

    private function warnung(string $text): void
    {
        if (!in_array($text, $this->warnungen, true)) {
            $this->warnungen[] = $text;
        }
    }

    /** A DOCTYPE anywhere (case-insensitive) - entities live there, and no e-invoice has one. */
    private static function hatDoctype(string $xml): bool
    {
        return stripos($xml, '<!DOCTYPE') !== false;
    }
}
