<?php
declare(strict_types=1);
// Keep imports in webtrees, which enforces its own manager permissions and CSRF.
add_action('admin_menu', static function (): void {
    add_management_page('GEDCOM en aantallen', 'GEDCOM en aantallen', 'manage_options', 'schuit-gedcom-import', static function (): void {
        echo '<div class="wrap"><h1>GEDCOM en aantallen</h1><p>Upload het nieuwe GEDCOM-bestand via het stamboombeheer in Webtrees. Het originele bestand blijft veilig bewaard; naamvelden worden bij de import genormaliseerd.</p><p>De website toont personen en gezinnen uit de actieve database. Na een volledig afgeronde import worden de aantallen binnen twee minuten vernieuwd. Tijdens de import blijven de vorige aantallen zichtbaar.</p>';
        printf('<p><a class="button button-primary" href="%s">Open Webtrees</a></p></div>', esc_url(home_url('/tree/')));
    });
});
