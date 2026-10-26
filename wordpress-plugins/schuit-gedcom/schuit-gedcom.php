<?php
/**
 * Plugin Name: SCHUIT GEDCOM beheer
 * Description: Privé GEDCOM-upload, controle en bevestigde verwerking voor Webtrees.
 * Version: 1.0.0
 * Requires PHP: 8.3
 * License: GPL-3.0-or-later
 */
declare(strict_types=1);
namespace Schuit\Gedcom;
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/includes/jobs.php';

const CAPABILITY = 'manage_schuit_gedcom';
register_activation_hook(__FILE__, static function (): void {
    get_role('administrator')?->add_cap(CAPABILITY);
    add_role('schuit_gedcom_manager', 'GEDCOM-beheerder', ['read'=>true, CAPABILITY=>true]);
});

function access(): void {
    if (!current_user_can(CAPABILITY)) { wp_die('Geen toegang tot GEDCOM-beheer.', '', ['response'=>403]); }
}
function visible(array $job): bool {
    return current_user_can('manage_options') || (int)$job['owner'] === get_current_user_id();
}
function url(string $id = ''): string {
    return admin_url('tools.php?page=schuit-gedcom-import' . ($id ? '&job=' . $id : ''));
}
function owned(string $id): array {
    $job = read($id);
    if (!visible($job)) { throw new \RuntimeException('Geen toegang tot deze taak.'); }
    return $job;
}
function labels(): array {
    return ['uploaded'=>'Wacht op controle', 'analyzing'=>'Bestand controleren', 'ready'=>'Klaar voor uw controle',
        'queued'=>'Bevestigd, wacht op verwerking', 'backing_up'=>'Veilige back-up maken', 'importing'=>'Verwerken in Webtrees',
        'completed'=>'Import voltooid', 'failed'=>'Niet verwerkt', 'cancelled'=>'Geannuleerd'];
}

add_action('admin_menu', static function (): void {
    add_management_page('GEDCOM-beheer', 'GEDCOM-beheer', CAPABILITY, 'schuit-gedcom-import', __NAMESPACE__ . '\\page');
});
add_action('admin_enqueue_scripts', static function (string $hook): void {
    if ($hook !== 'tools_page_schuit-gedcom-import') { return; }
    wp_enqueue_style('schuit-gedcom', plugins_url('style.css', __FILE__), [], '1.0.0');
    wp_enqueue_script('schuit-gedcom', plugins_url('status.js', __FILE__), [], '1.0.0', true);
    wp_localize_script('schuit-gedcom', 'schuitGedcom', ['endpoint'=>admin_url('admin-ajax.php'), 'nonce'=>wp_create_nonce('schuit_gedcom_status')]);
});

add_action('admin_post_schuit_gedcom', static function (): void {
    access(); check_admin_referer('schuit_gedcom');
    $id = sanitize_text_field(wp_unslash($_POST['job'] ?? ''));
    try {
        $action = sanitize_key($_POST['operation'] ?? '');
        if ($action === 'upload') {
            $file = $_FILES['gedcom'] ?? [];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
                throw new \RuntimeException('Upload mislukt. Kies een .ged-bestand van maximaal 100 MB.');
            }
            if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'ged' || filesize($file['tmp_name']) > MAX_BYTES) {
                throw new \RuntimeException('Alleen .ged-bestanden tot 100 MB zijn toegestaan.');
            }
            $queue = fopen(root() . '/queue.lock', 'c');
            if (!$queue || !flock($queue, LOCK_EX)) { throw new \RuntimeException('De opslag is niet beschikbaar.'); }
            try {
                $active = 0; $bytes = 0;
                foreach (jobs() as $existing) {
                    if (!in_array($existing['state'], ['completed','failed','cancelled'], true)) { ++$active; }
                    $bytes += (int)($existing['bytes'] ?? 0);
                }
                if ($active >= 3 || $bytes + filesize($file['tmp_name']) > 500 * 1024 * 1024) {
                    throw new \RuntimeException('De wachtrij is vol. Rond bestaande taken af of vraag de beheerder om opslag vrij te maken.');
                }
                $id = bin2hex(random_bytes(16)); $folder = directory($id);
                if (!mkdir($folder, 0700)) { throw new \RuntimeException('Uploadmap aanmaken mislukt.'); }
                if (!move_uploaded_file($file['tmp_name'], $folder . '/original.ged')) { throw new \RuntimeException('Upload bewaren mislukt.'); }
                chmod($folder . '/original.ged', 0600);
                write(['id'=>$id, 'owner'=>get_current_user_id(), 'filename'=>sanitize_file_name($file['name']),
                    'bytes'=>filesize($folder . '/original.ged'), 'created_at'=>gmdate('c'), 'state'=>'uploaded']);
            } finally { flock($queue, LOCK_UN); fclose($queue); }
        } elseif (in_array($action, ['confirm','cancel'], true)) {
            owned($id);
            change($id, static function (array $job) use ($action): array {
                if ($job['state'] !== 'ready') { throw new \RuntimeException('Deze taak kan niet meer worden gewijzigd.'); }
                if ($action === 'confirm' && ($_POST['replace_confirmed'] ?? '') !== 'yes') {
                    throw new \RuntimeException('Bevestig eerst dat de gecontroleerde gegevens de huidige gegevens mogen vervangen.');
                }
                $job['state'] = $action === 'confirm' ? 'queued' : 'cancelled';
                $job['confirmed_by'] = get_current_user_id(); $job['confirmed_at'] = gmdate('c');
                return $job;
            });
        } else { throw new \RuntimeException('Ongeldige actie.'); }
        wp_safe_redirect(url($id)); exit;
    } catch (\Throwable $e) {
        wp_die(esc_html($e instanceof \RuntimeException ? $e->getMessage() : 'De actie is mislukt. Probeer opnieuw of neem contact op met de beheerder.'), 'GEDCOM-beheer', ['response'=>400, 'back_link'=>true]);
    }
});

add_action('wp_ajax_schuit_gedcom_status', static function (): void {
    access(); check_ajax_referer('schuit_gedcom_status');
    try {
        $job = owned(sanitize_text_field(wp_unslash($_GET['job'] ?? '')));
        wp_send_json_success(['state'=>$job['state'], 'label'=>labels()[$job['state']] ?? $job['state'],
            'progress'=>$job['progress'] ?? '', 'updated'=>$job['updated_at']]);
    } catch (\Throwable) { wp_send_json_error(['message'=>'Taak niet beschikbaar.'], 404); }
});

function form(string $operation, string $id = ''): void {
    echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('schuit_gedcom');
    echo '<input type="hidden" name="action" value="schuit_gedcom"><input type="hidden" name="operation" value="' . esc_attr($operation) . '"><input type="hidden" name="job" value="' . esc_attr($id) . '">';
}
function page(): void {
    access();
    echo '<div class="wrap schuit-gedcom"><p class="sg-eyebrow">SCHUIT · WEBTREES</p><h1>GEDCOM-beheer</h1><p>Uploaden → Controleren → Bevestigen → Verwerken</p>';
    $id = sanitize_text_field(wp_unslash($_GET['job'] ?? ''));
    if ($id) {
        try { $job = owned($id); } catch (\Throwable) { echo '<p>Taak niet beschikbaar.</p></div>'; return; }
        echo '<p><a href="' . esc_url(url()) . '">← Alle uploads</a></p><section class="sg-panel" data-job="' . esc_attr($id) . '" data-state="' . esc_attr($job['state']) . '"><h2>' . esc_html($job['filename']) . '</h2><p><strong id="sg-state" role="status">' . esc_html(labels()[$job['state']]) . '</strong></p><p id="sg-progress" aria-live="polite">' . esc_html((string)($job['progress'] ?? '')) . '</p><p id="sg-connection"></p>';
        if (isset($job['error'])) { echo '<div class="notice notice-error inline"><p>' . esc_html($job['error']) . '</p></div>'; }
        if (isset($job['analysis'])) {
            $a = $job['analysis']; $current = $job['baseline']['counts'];
            echo '<div class="sg-metrics">';
            foreach (['INDI'=>'Personen', 'FAM'=>'Gezinnen', 'SOUR'=>'Bronnen', 'OBJE'=>'Mediarecords'] as $key=>$label) {
                echo '<div><span>' . esc_html($label) . '</span><strong>' . esc_html(number_format_i18n($a['counts'][$key])) . '</strong>';
                if (isset($current[$key])) {
                    $delta = $a['counts'][$key] - $current[$key];
                    echo '<small>Nu: ' . esc_html(number_format_i18n($current[$key])) . ' · Verschil: ' . ($delta > 0 ? '+' : '') . esc_html(number_format_i18n($delta)) . '</small>';
                }
                echo '</div>';
            }
            echo '</div><dl class="sg-details">';
            foreach (['Doelbestand in Webtrees'=>$job['baseline']['title'], 'Bestandsgrootte'=>size_format($a['bytes'], 2), 'GEDCOM-versie'=>$a['version'],
                'Tekencodering'=>$a['encoding'] . ' → UTF-8', 'Records'=>number_format_i18n($a['records']), 'Aangepaste naamregels'=>number_format_i18n($a['normalized_names']), 'SHA-256'=>$a['sha256']] as $label=>$value) {
                echo '<dt>' . esc_html($label) . '</dt><dd>' . esc_html((string)$value) . '</dd>';
            }
            echo '</dl><p>Het origineel blijft privé bewaard. Alleen naamregels krijgen waar nodig een spatie vóór “/”. Links en bestandspaden blijven ongewijzigd. Mediarecords bevatten verwijzingen; foto’s en documenten worden niet met een GEDCOM-bestand meegeleverd.</p>';
            foreach ($a['warnings'] as $warning) { echo '<div class="notice notice-warning inline"><p>' . esc_html($warning) . '</p></div>'; }
        }
        if ($job['state'] === 'ready') {
            echo '<h3>Controleer en bevestig</h3><p>De import vervangt de genealogische records in het hierboven genoemde bestand. Accounts, toegangsrechten, privacyinstellingen en bestaande mediabestanden blijven behouden. Eerst wordt een versleutelde back-up gemaakt. Webtrees is tijdens de verwerking tijdelijk niet beschikbaar; deze statuspagina blijft werken.</p>';
            form('confirm', $id);
            echo '<p><label><input type="checkbox" name="replace_confirmed" value="yes" required> Ik heb de aantallen en waarschuwingen gecontroleerd en geef toestemming om de huidige genealogische gegevens te vervangen.</label></p><button class="button button-primary">Bevestigen en verwerken</button></form>';
            form('cancel', $id); echo '<p><button class="button">Deze upload annuleren</button></p></form>';
        } elseif ($job['state'] === 'completed') {
            echo '<p>De import is gecontroleerd en afgerond. De aantallen op de website worden automatisch vernieuwd.</p><a class="button button-primary" href="' . esc_url(home_url('/tree/')) . '">Open Webtrees</a>';
        }
        echo '</section>';
    } else {
        echo '<section class="sg-panel"><h2>Nieuw bestand uploaden</h2><p>Kies een GEDCOM 5.5 of 5.5.1-bestand (.ged), maximaal 100 MB. Uploaden verandert nog niets in Webtrees. U krijgt eerst een controlepagina.</p>';
        form('upload'); echo '<p><label for="sg-file">GEDCOM-bestand</label><br><input id="sg-file" type="file" name="gedcom" accept=".ged" required></p><button class="button button-primary">Uploaden en controleren</button></form><p>Bestanden staan buiten de publieke website. Uploads en controleresultaten worden na 30 dagen verwijderd. Versleutelde back-ups hebben een afzonderlijke bewaartermijn.</p></section><h2>Uploads</h2><table class="widefat striped"><thead><tr><th>Bestand</th><th>Status</th><th>Geüpload (UTC)</th></tr></thead><tbody>';
        foreach (jobs() as $job) {
            if (!visible($job)) { continue; }
            echo '<tr><td><a href="' . esc_url(url($job['id'])) . '">' . esc_html($job['filename']) . '</a></td><td>' . esc_html(labels()[$job['state']]) . '</td><td>' . esc_html($job['created_at']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
}
