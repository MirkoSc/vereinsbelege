-- Categories for income and expenses (M6-1, issue #35, docs/spec/
-- 02-datenmodell.md "Kategorien – Startbestand").
--
-- Plaintext on purpose, like `cost_center`: master data the club maintains,
-- not club data. SQL filters and groups receipts and bookings by the id.
--
-- `direction` (App\Domain\CategoryDirection): `einnahme`, `ausgabe` or
-- `beide`. `color` is a key of the fixed palette App\Domain\CategoryColor -
-- the CSP allows no inline styles, so a free hex value could not be shown.
-- `default_sphere` stays NULL while the tax spheres are switched off (E-15).
-- `ai_hint` goes into the prompt of the AI categorisation (M7-7).
--
-- `parent_id` is prepared but not yet maintained (the admin page is flat).
-- Deleting is only allowed for unused categories: every later table that
-- points here (invoice, bank_transaction, supplier, assignment_rule) adds
-- its foreign key with ON DELETE RESTRICT and is counted in
-- App\Repository\CategoryRepository::usageCount().
CREATE TABLE category (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    direction VARCHAR(16) NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    default_sphere VARCHAR(32) NULL,
    color VARCHAR(16) NULL,
    sort INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    ai_hint VARCHAR(500) NOT NULL DEFAULT '',
    UNIQUE KEY uq_category_name (name),
    KEY idx_category_direction (direction, active, sort),
    CONSTRAINT fk_category_parent FOREIGN KEY (parent_id) REFERENCES category (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The starting set of the spec, editable afterwards. `sort` counts per
-- direction (10, 20, ...), the same normalisation the admin page writes.
INSERT INTO category (name, direction, color, sort, ai_hint) VALUES
('Mitgliedsbeiträge', 'einnahme', 'gruen', 10, 'Mitgliedsbeiträge, Aufnahmegebühren, Beitragseinzug per Lastschrift'),
('Spenden', 'einnahme', 'gruen', 20, 'Geldspenden, Sachspenden, Spendenaktionen, Crowdfunding'),
('Sponsoring & Bandenwerbung', 'einnahme', 'blau', 30, 'Sponsoringverträge, Bandenwerbung, Trikotwerbung, Anzeigen im Stadionheft'),
('Zuschüsse (Verband, Gemeinde)', 'einnahme', 'tuerkis', 40, 'Zuschüsse von Gemeinde, Landkreis, Sportbund, Landesverband, Übungsleiterzuschuss'),
('Eintrittsgelder', 'einnahme', 'blau', 50, 'Eintritt zu Heimspielen, Turnieren und Veranstaltungen, Dauerkarten'),
('Verkauf Speisen & Getränke', 'einnahme', 'orange', 60, 'Kiosk, Getränkeverkauf, Grillstand, Kuchenverkauf, Vereinsheim-Ausschank'),
('Vermietung Vereinsheim/Platz', 'einnahme', 'lila', 70, 'Miete für Vereinsheim, Sportplatz, Halle, Nebenkostenumlage'),
('Veranstaltungserlöse', 'einnahme', 'gelb', 80, 'Erlöse aus Festen, Turnieren, Tombola, Startgelder'),
('Sonstige Einnahmen', 'einnahme', 'grau', 90, 'Erstattungen, Zinsen, Schadensersatz, alles ohne passende Kategorie'),

('Verpflegung & Bewirtung', 'ausgabe', 'orange', 10, 'Getränke, Lebensmittel, Einkauf für Kiosk, Essen nach dem Spiel, Bewirtung von Gästen'),
('Platzpflege & Grünanlagen', 'ausgabe', 'gruen', 20, 'Rasendünger, Mäharbeiten, Sand, Linierfarbe, Rasensaat, Bewässerung'),
('Sportplatz-Instandhaltung', 'ausgabe', 'gruen', 30, 'Tore, Netze, Flutlicht, Zäune, Reparatur Kunstrasen, Ballfangzaun'),
('Vereinsheim & Gebäude', 'ausgabe', 'lila', 40, 'Reparaturen, Handwerker, Reinigung, Müllabfuhr, Grundsteuer, Einrichtung'),
('Energie & Wasser', 'ausgabe', 'gelb', 50, 'Strom, Gas, Heizöl, Wasser, Abwasser, Abschlagszahlungen der Stadtwerke'),
('Sportausrüstung & Trikots', 'ausgabe', 'blau', 60, 'Trikots, Trainingsanzüge, Schuhe, Torwarthandschuhe, Beflockung'),
('Bälle & Trainingsmaterial', 'ausgabe', 'blau', 70, 'Bälle, Hütchen, Leibchen, Stangen, Ballpumpen, Trainingstore'),
('Spielbetrieb & Schiedsrichter', 'ausgabe', 'tuerkis', 80, 'Schiedsrichterkosten, Spielgebühren, Passgebühren, Strafen des Verbands'),
('Verbandsbeiträge & Gebühren', 'ausgabe', 'tuerkis', 90, 'Beiträge an Landessportbund, Fachverband, GEMA, Rundfunkbeitrag'),
('Versicherungen', 'ausgabe', 'grau', 100, 'Vereinshaftpflicht, Gebäudeversicherung, Unfallversicherung, Inventar'),
('Veranstaltungen & Feste', 'ausgabe', 'gelb', 110, 'Vereinsfest, Weihnachtsfeier, Turniere, Zeltmiete, Musik, Dekoration'),
('Fahrtkosten', 'ausgabe', 'rot', 120, 'Busfahrten zu Auswärtsspielen, Kilometergeld, Tankquittungen, Bahntickets'),
('Jugendarbeit', 'ausgabe', 'orange', 130, 'Jugendfreizeit, Zeltlager, Jugendturniere, Ausflüge, Jugendtrainer-Fortbildung'),
('Übungsleiter & Ehrenamt', 'ausgabe', 'rot', 140, 'Übungsleiterpauschale, Ehrenamtspauschale, Aufwandsentschädigungen, Lizenzkosten'),
('Büro & Verwaltung', 'ausgabe', 'grau', 150, 'Porto, Papier, Druckerpatronen, Briefumschläge, Notar, Vereinsregister'),
('IT & Software', 'ausgabe', 'lila', 160, 'Webhosting, Domain, Software-Abos, Vereinsverwaltung, Computer, Telefon, Internet'),
('Bankgebühren', 'ausgabe', 'grau', 170, 'Kontoführung, Buchungsgebühren, Kartengebühren, Lastschriftrückgaben'),
('Werbung & Sponsoring', 'ausgabe', 'blau', 180, 'Flyer, Plakate, Anzeigen, Banner, Werbeartikel, Stadionheft-Druck'),
('Ehrungen & Geschenke', 'ausgabe', 'rot', 190, 'Pokale, Medaillen, Urkunden, Blumen, Präsente für Jubilare'),
('Sonstiges', 'ausgabe', 'grau', 200, 'Ausgaben ohne passende Kategorie');
