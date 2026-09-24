<?php
declare(strict_types=1);

/**
 * Describes every editable piece of content. The admin forms are generated
 * from this, so a new field only needs to be added here (plus a default in
 * defaults.json and wherever the template shows it).
 *
 * Field types: text, textarea, markdown, image, file, date, bool, color,
 * url, email, select (options), lines (one entry per line), repeater (fields).
 */
function schema(): array
{
    $md = 'Formatierung: **fett**, *kursiv*, [Linktext](https://…), "- " für Listen, "# " für Zwischenüberschriften.';

    return [
        'site' => [
            'label' => 'Allgemein & Design',
            'type' => 'object',
            'fields' => [
                ['key' => 'name', 'label' => 'Kurzname', 'type' => 'text'],
                ['key' => 'full_name', 'label' => 'Voller Name', 'type' => 'text'],
                ['key' => 'description', 'label' => 'Beschreibung für Suchmaschinen', 'type' => 'textarea'],
                ['key' => 'school', 'label' => 'Schule', 'type' => 'text'],
                ['key' => 'address', 'label' => 'Adresse (Footer)', 'type' => 'textarea'],
                ['key' => 'email', 'label' => 'Kontakt-E-Mail', 'type' => 'email'],
                ['key' => 'instagram', 'label' => 'Instagram URL', 'type' => 'url'],
                ['key' => 'school_url', 'label' => 'Website der Schule', 'type' => 'url'],
                ['key' => 'logo', 'label' => 'Logo (optional, ersetzt das Text-Logo)', 'type' => 'image'],
                ['key' => 'og_image', 'label' => 'Vorschaubild für Social Media', 'type' => 'image'],
                ['key' => 'color_un', 'label' => 'UN-Blau (Akzente, Countdown)', 'type' => 'color'],
                ['key' => 'color_accent', 'label' => 'Schulblau (Links, Buttons)', 'type' => 'color'],
                ['key' => 'color_ink', 'label' => 'Dunkle Farbe (Text, Footer)', 'type' => 'color'],
                ['key' => 'color_paper', 'label' => 'Hintergrundfarbe', 'type' => 'color'],
                ['key' => 'banner_show', 'label' => 'Hinweisleiste oben anzeigen', 'type' => 'bool'],
                ['key' => 'banner_text', 'label' => 'Text der Hinweisleiste', 'type' => 'text'],
                ['key' => 'banner_link', 'label' => 'Link der Hinweisleiste', 'type' => 'text', 'help' => 'z. B. /register oder https://…'],
            ],
        ],
        'home' => [
            'label' => 'Startseite',
            'type' => 'object',
            'fields' => [
                ['key' => 'hero_kicker', 'label' => 'Kleine Zeile über dem Titel', 'type' => 'text'],
                ['key' => 'hero_title', 'label' => 'Großer Titel', 'type' => 'textarea', 'help' => 'Wort in *Sternchen* setzen, um es kursiv/farbig hervorzuheben.'],
                ['key' => 'hero_text', 'label' => 'Einleitungstext', 'type' => 'textarea'],
                ['key' => 'hero_image', 'label' => 'Titelfoto', 'type' => 'image'],
                ['key' => 'hero_image_caption', 'label' => 'Bildunterschrift', 'type' => 'text'],
                ['key' => 'show_countdown', 'label' => 'Countdown zur Konferenz anzeigen', 'type' => 'bool'],
                ['key' => 'about_heading', 'label' => 'Überschrift „Über uns“', 'type' => 'text'],
                ['key' => 'about_text', 'label' => 'Text „Über uns“', 'type' => 'markdown', 'help' => $md],
                ['key' => 'stats', 'label' => 'Zahlen', 'type' => 'repeater', 'fields' => [
                    ['key' => 'value', 'label' => 'Zahl', 'type' => 'text'],
                    ['key' => 'label', 'label' => 'Beschriftung', 'type' => 'text'],
                ]],
                ['key' => 'steps_heading', 'label' => 'Überschrift „So funktioniert’s“', 'type' => 'text'],
                ['key' => 'steps', 'label' => 'Schritte „So funktioniert’s“', 'type' => 'repeater', 'fields' => [
                    ['key' => 'title', 'label' => 'Titel', 'type' => 'text'],
                    ['key' => 'text', 'label' => 'Text', 'type' => 'textarea'],
                ]],
                ['key' => 'quote', 'label' => 'Zitat', 'type' => 'textarea'],
                ['key' => 'quote_author', 'label' => 'Zitat von', 'type' => 'text'],
                ['key' => 'cta_heading', 'label' => 'Aufruf unten: Überschrift', 'type' => 'text'],
                ['key' => 'cta_text', 'label' => 'Aufruf unten: Text', 'type' => 'textarea'],
            ],
        ],
        'conference' => [
            'label' => 'Konferenz',
            'type' => 'object',
            'fields' => [
                ['key' => 'edition', 'label' => 'Ausgabe (z. B. OMUN 2027)', 'type' => 'text'],
                ['key' => 'motto', 'label' => 'Motto / Leitthema', 'type' => 'text'],
                ['key' => 'date_start', 'label' => 'Erster Tag', 'type' => 'date'],
                ['key' => 'date_end', 'label' => 'Letzter Tag', 'type' => 'date'],
                ['key' => 'start_time', 'label' => 'Beginn am ersten Tag (für den Countdown)', 'type' => 'text', 'help' => 'HH:MM, z. B. 09:00'],
                ['key' => 'venue', 'label' => 'Ort', 'type' => 'text'],
                ['key' => 'venue_address', 'label' => 'Adresse des Orts', 'type' => 'textarea'],
                ['key' => 'map_url', 'label' => 'Link zur Karte', 'type' => 'url'],
                ['key' => 'fee', 'label' => 'Teilnahmebeitrag', 'type' => 'text'],
                ['key' => 'language', 'label' => 'Arbeitssprache', 'type' => 'text'],
                ['key' => 'intro', 'label' => 'Einleitung', 'type' => 'markdown', 'help' => $md],
                ['key' => 'schedule', 'label' => 'Zeitplan', 'type' => 'repeater', 'fields' => [
                    ['key' => 'day', 'label' => 'Tag', 'type' => 'text'],
                    ['key' => 'time', 'label' => 'Uhrzeit', 'type' => 'text'],
                    ['key' => 'title', 'label' => 'Programmpunkt', 'type' => 'text'],
                    ['key' => 'location', 'label' => 'Wo?', 'type' => 'text'],
                ]],
                ['key' => 'body', 'label' => 'Weitere Informationen', 'type' => 'markdown', 'help' => $md],
            ],
        ],
        'registration' => [
            'label' => 'Anmeldung',
            'type' => 'object',
            'fields' => [
                ['key' => 'open', 'label' => 'Anmeldung ist geöffnet', 'type' => 'bool'],
                ['key' => 'deadline', 'label' => 'Anmeldeschluss', 'type' => 'date'],
                ['key' => 'intro', 'label' => 'Text über dem Formular', 'type' => 'markdown', 'help' => $md],
                ['key' => 'roles', 'label' => 'Teilnahmearten (eine pro Zeile)', 'type' => 'lines'],
                ['key' => 'closed_message', 'label' => 'Text, wenn die Anmeldung geschlossen ist', 'type' => 'markdown'],
                ['key' => 'success_message', 'label' => 'Text nach erfolgreicher Anmeldung', 'type' => 'markdown'],
                ['key' => 'notify_email', 'label' => 'Benachrichtigung bei neuer Anmeldung an', 'type' => 'email', 'help' => 'Leer lassen = keine E-Mails.'],
            ],
        ],
        'committees' => [
            'label' => 'Gremien',
            'type' => 'list',
            'title_field' => 'name',
            'slug_from' => 'name',
            'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                ['key' => 'abbr', 'label' => 'Abkürzung', 'type' => 'text'],
                ['key' => 'level', 'label' => 'Niveau', 'type' => 'select', 'options' => ['Beginner', 'Intermediate', 'Advanced']],
                ['key' => 'topics', 'label' => 'Themen (eins pro Zeile)', 'type' => 'lines'],
                ['key' => 'summary', 'label' => 'Kurzbeschreibung', 'type' => 'textarea'],
                ['key' => 'description', 'label' => 'Ausführliche Beschreibung', 'type' => 'markdown'],
                ['key' => 'chairs', 'label' => 'Vorsitz', 'type' => 'text'],
                ['key' => 'image', 'label' => 'Bild', 'type' => 'image'],
                ['key' => 'study_guide', 'label' => 'Study Guide (PDF)', 'type' => 'file'],
            ],
        ],
        'team' => [
            'label' => 'Team',
            'type' => 'list',
            'title_field' => 'name',
            'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                ['key' => 'role', 'label' => 'Rolle', 'type' => 'text'],
                ['key' => 'group', 'label' => 'Gruppe', 'type' => 'select', 'options' => ['Secretariat', 'Chairs', 'Organising Team', 'Teachers']],
                ['key' => 'photo', 'label' => 'Foto', 'type' => 'image'],
                ['key' => 'bio', 'label' => 'Kurzvorstellung', 'type' => 'textarea'],
            ],
        ],
        'news' => [
            'label' => 'News',
            'type' => 'list',
            'title_field' => 'title',
            'slug_from' => 'title',
            'fields' => [
                ['key' => 'title', 'label' => 'Titel', 'type' => 'text'],
                ['key' => 'date', 'label' => 'Datum', 'type' => 'date'],
                ['key' => 'image', 'label' => 'Bild', 'type' => 'image'],
                ['key' => 'excerpt', 'label' => 'Teaser', 'type' => 'textarea'],
                ['key' => 'body', 'label' => 'Artikel', 'type' => 'markdown', 'help' => $md],
                ['key' => 'published', 'label' => 'Veröffentlicht', 'type' => 'bool', 'default' => true],
            ],
        ],
        'gallery' => [
            'label' => 'Galerie',
            'type' => 'list',
            'title_field' => 'caption',
            'fields' => [
                ['key' => 'image', 'label' => 'Foto', 'type' => 'image'],
                ['key' => 'caption', 'label' => 'Bildunterschrift', 'type' => 'text'],
                ['key' => 'year', 'label' => 'Jahr / Album', 'type' => 'text'],
            ],
        ],
        'faq' => [
            'label' => 'FAQ',
            'type' => 'list',
            'title_field' => 'question',
            'fields' => [
                ['key' => 'question', 'label' => 'Frage', 'type' => 'text'],
                ['key' => 'answer', 'label' => 'Antwort', 'type' => 'markdown', 'help' => $md],
            ],
        ],
        'downloads' => [
            'label' => 'Downloads',
            'type' => 'list',
            'title_field' => 'title',
            'fields' => [
                ['key' => 'title', 'label' => 'Titel', 'type' => 'text'],
                ['key' => 'file', 'label' => 'Datei', 'type' => 'file'],
                ['key' => 'category', 'label' => 'Kategorie', 'type' => 'select', 'options' => ['Conference', 'Preparation', 'Forms', 'Other']],
                ['key' => 'description', 'label' => 'Beschreibung', 'type' => 'text'],
                ['key' => 'featured', 'label' => 'Auf der Startseite zeigen', 'type' => 'bool'],
            ],
        ],
        'sponsors' => [
            'label' => 'Sponsoren & Partner',
            'type' => 'list',
            'title_field' => 'name',
            'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                ['key' => 'logo', 'label' => 'Logo', 'type' => 'image'],
                ['key' => 'url', 'label' => 'Website', 'type' => 'url'],
                ['key' => 'text', 'label' => 'Kurzer Text', 'type' => 'textarea'],
            ],
        ],
        'archive' => [
            'label' => 'Archiv',
            'type' => 'list',
            'title_field' => 'title',
            'fields' => [
                ['key' => 'title', 'label' => 'Titel (z. B. OMUN 2026)', 'type' => 'text'],
                ['key' => 'dates', 'label' => 'Zeitraum', 'type' => 'text'],
                ['key' => 'motto', 'label' => 'Motto', 'type' => 'text'],
                ['key' => 'image', 'label' => 'Bild', 'type' => 'image'],
                ['key' => 'text', 'label' => 'Rückblick', 'type' => 'markdown'],
                ['key' => 'file', 'label' => 'Dokument (z. B. Resolutionen)', 'type' => 'file'],
            ],
        ],
        'pages' => [
            'label' => 'Weitere Seiten',
            'type' => 'list',
            'title_field' => 'title',
            'slug_from' => 'title',
            'fields' => [
                ['key' => 'title', 'label' => 'Titel', 'type' => 'text'],
                ['key' => 'lead', 'label' => 'Einleitung', 'type' => 'textarea'],
                ['key' => 'body', 'label' => 'Inhalt', 'type' => 'markdown', 'help' => $md],
                ['key' => 'in_nav', 'label' => 'Im Hauptmenü anzeigen', 'type' => 'bool'],
                ['key' => 'in_footer', 'label' => 'Im Footer anzeigen', 'type' => 'bool'],
            ],
        ],
        'legal' => [
            'label' => 'Impressum & Datenschutz',
            'type' => 'object',
            'fields' => [
                ['key' => 'imprint', 'label' => 'Impressum', 'type' => 'markdown', 'help' => $md],
                ['key' => 'privacy', 'label' => 'Datenschutzerklärung', 'type' => 'markdown', 'help' => $md],
            ],
        ],
    ];
}
