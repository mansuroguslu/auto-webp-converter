<?php
/*
Plugin Name: Auto WebP Converter
Plugin URI: https://aetron.eu
Description: Konvertiert automatisch alle hochgeladenen Bildformate in WebP.
Version: 1.1
Author: Mansur Oguslu
Author URI: https://oguslu.com
License: GPL2
Requires at least: 6.7.2
Requires PHP: 8.2.28
*/

if (!defined('ABSPATH')) {
    exit; // Sicherheitsschutz
}

// Hook in den Upload-Prozess
add_filter('wp_handle_upload', 'convert_image_to_webp');

function convert_image_to_webp($upload)
{
    $file_path = $upload['file'];  // Pfad zum hochgeladenen Bild
    $file_type = $upload['type'];  // MIME-Typ der Datei

    // Unterstützte Bildformate außer SVG
    $allowed_mimes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/bmp',
        'image/tiff',
        'image/x-icon' // ICO-Dateien
    ];

    if (!in_array($file_type, $allowed_mimes)) {
        return $upload; // Falls kein unterstütztes Bildformat, abbrechen
    }

    // Ziel-WebP-Datei erstellen
    $webp_path = preg_replace('/\.(jpe?g|png|gif|bmp|tiff|ico)$/i', '.webp', $file_path);

    // WebP konvertieren
    if (convert_to_webp($file_path, $webp_path, $file_type)) {
        // Ersetze das Bild in der Mediathek mit der WebP-Version
        $upload['file'] = $webp_path;
        $upload['url'] = preg_replace('/\.(jpe?g|png|gif|bmp|tiff|ico)$/i', '.webp', $upload['url']);
        $upload['type'] = 'image/webp';
    }

    return $upload;
}

function convert_to_webp($source, $destination, $mime)
{
    if (extension_loaded('imagick')) {
        $image = new Imagick($source);
        $image->setImageFormat('webp');

        // Qualitativ hochwertige, aber optimierte WebP-Kompression
        $image->setImageCompressionQuality(75); // Weniger = höhere Kompression
        $image->setOption('webp:lossless', 'false'); // Verlustbehaftete WebP-Konvertierung
        $image->stripImage(); // Entfernt Metadaten für geringere Dateigröße
        $image->setInterlaceScheme(Imagick::INTERLACE_NO); // Deaktiviert Interlace (spart Platz)

        if ($image->writeImage($destination)) {
            return true;
        }
    } elseif (function_exists('imagewebp')) {
        switch ($mime) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($source);
                break;
            case 'image/png':
                $image = imagecreatefrompng($source);
                imagepalettetotruecolor($image); // Verhindert schlechte Kompression bei PNG
                break;
            case 'image/gif':
                $image = imagecreatefromgif($source);
                break;
            case 'image/bmp':
                $image = imagecreatefrombmp($source);
                break;
            case 'image/x-icon':
                $image = imagecreatefromstring(file_get_contents($source));
                break;
            default:
                return false;
        }

        if (!$image) return false;

        // WebP mit guter Komprimierung speichern
        $result = imagewebp($image, $destination, 75); // Qualität auf 75 reduzieren
        imagedestroy($image);
        return $result;
    }

    return false; // Falls keine Unterstützung vorhanden ist
}
// Beim Aktivieren des Plugins eine Tabelle erstellen
register_activation_hook(__FILE__, 'awc_create_db_table');

function awc_create_db_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'awc_converted_images';

    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id INT AUTO_INCREMENT PRIMARY KEY,
        original_path TEXT NOT NULL,
        webp_path TEXT NOT NULL,
        converted_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
// Admin-Menü hinzufügen
add_action('admin_menu', 'awc_add_admin_page');

function awc_add_admin_page()
{
    add_menu_page(
        'Auto WebP Converter',
        'WebP Converter',
        'manage_options',
        'awc-webp-converter',
        'awc_admin_page',
        'dashicons-image-filter',
        20
    );
}

// Admin-Seite für Plugin-Info anpassen
function awc_admin_page()
{
    echo '<div class="wrap">';
    echo '<h1>Auto WebP Converter</h1>';
    echo '<p>Danke, dass Sie das <strong>Auto WebP Converter</strong> Plugin installiert haben! Dieses Plugin hilft dabei, Bilder automatisch in das effiziente WebP-Format zu konvertieren.</p>';

    echo '<h2>Über den Entwickler</h2>';
    echo '<p><strong>Mansur Oguslu</strong><br>';
    echo 'CEO of <a href="https://aetron.eu" target="_blank">AETRON.EU</a><br>';
    echo '📞 <a href="tel:+32480205790">+32 480 205 790</a><br>';
    echo '📧 <a href="mailto:mansur@aetron.eu">mansur@aetron.eu</a></p>';

    echo '<p>Ich freue mich über Ihr Feedback! Da es sich um ein <strong>Open-Source-Projekt</strong> handelt, sind Vorschläge und Verbesserungen immer willkommen.</p>';
    echo '<p><a href="https://github.com/mansuroguslu/auto-webp-converter" target="_blank" class="button button-primary">GitHub-Projekt ansehen</a></p>';

    echo '</div>';
}

// Füge einen Button in der Medienliste hinzu
add_filter('manage_media_columns', 'awc_add_webp_column');

function awc_add_webp_column($columns)
{
    $columns['awc_webp'] = 'WebP Konvertierung';
    return $columns;
}

add_action('manage_media_custom_column', 'awc_custom_column_content', 10, 2);

function awc_custom_column_content($column_name, $post_id)
{
    if ($column_name === 'awc_webp') {
        $file_path = get_attached_file($post_id);
        $webp_path = preg_replace('/\.(jpe?g|png|gif|bmp|tiff|ico)$/i', '.webp', $file_path);

        if (file_exists($webp_path)) {
            echo '<span style="color: green; font-weight: bold;">✓ Bereits konvertiert</span>';
        } else {
            echo '<button class="button awc-convert-webp" data-id="' . $post_id . '">Zu WebP konvertieren</button>';
        }
    }
}

// AJAX-Script für den Button hinzufügen
add_action('admin_footer', 'awc_add_ajax_script');

function awc_add_ajax_script()
{
    echo '<script>
    document.addEventListener("DOMContentLoaded", function() {
        document.querySelectorAll(".awc-convert-webp").forEach(button => {
            button.addEventListener("click", function() {
                let postId = this.getAttribute("data-id");
                let btn = this;

                btn.innerHTML = "Konvertiere...";
                btn.disabled = true;

                fetch("' . admin_url('admin-ajax.php') . '?action=awc_convert_single&id=" + postId)
                .then(response => response.text())
                .then(data => {
                    if (data.includes("Erfolgreich")) {
                        btn.outerHTML = "<span style=\'color: green; font-weight: bold;\'>✓ Erfolgreich konvertiert</span>";
                    } else {
                        btn.innerHTML = "Fehlgeschlagen";
                        btn.disabled = false;
                    }
                });
            });
        });
    });
    </script>';
}
add_action('wp_ajax_awc_convert_single', 'awc_convert_single_image');

function awc_convert_single_image()
{
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        echo "Fehler: Ungültige Bild-ID";
        wp_die();
    }

    $post_id = intval($_GET['id']);
    $file_path = get_attached_file($post_id);
    $mime = mime_content_type($file_path);

    // Unterstützte Bildformate
    $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/tiff', 'image/x-icon'];
    if (!in_array($mime, $allowed_mimes)) {
        echo "Fehler: Nicht unterstütztes Format";
        wp_die();
    }

    // WebP-Pfad bestimmen
    $webp_path = preg_replace('/\.(jpe?g|png|gif|bmp|tiff|ico)$/i', '.webp', $file_path);

    // Konvertieren
    if (convert_to_webp($file_path, $webp_path, $mime)) {
        global $wpdb;

        // Prüfen, ob Datei wirklich existiert
        if (!file_exists($webp_path)) {
            echo "Fehler: WebP-Datei wurde nicht erstellt!";
            wp_die();
        }

        // WordPress Mediathek aktualisieren
        update_attached_file($post_id, $webp_path);
        $webp_url = wp_get_attachment_url($post_id);

        // Metadaten anpassen
        update_post_meta($post_id, '_wp_attached_file', str_replace(wp_basename($file_path), wp_basename($webp_path), get_post_meta($post_id, '_wp_attached_file', true)));

        // GUID (permalink) aktualisieren
        $wpdb->update($wpdb->posts, ['guid' => $webp_url], ['ID' => $post_id]);

        echo "Erfolgreich konvertiert!";
    } else {
        echo "Fehler bei der Konvertierung.";
    }

    wp_die();
}
// Neue Option in der "Bulk Actions"-Liste hinzufügen
add_filter('bulk_actions-upload', 'awc_add_bulk_action');

function awc_add_bulk_action($bulk_actions)
{
    $bulk_actions['awc_convert_bulk'] = 'Zu WebP konvertieren';
    return $bulk_actions;
}

// Verarbeiten der Massenaktion
add_filter('handle_bulk_actions-upload', 'awc_handle_bulk_action', 10, 3);

function awc_handle_bulk_action($redirect_to, $action, $post_ids)
{
    if ($action !== 'awc_convert_bulk') {
        return $redirect_to;
    }

    // Start AJAX-Verarbeitung für die ausgewählten Bilder
    wp_redirect(add_query_arg('bulk_awc_converted', count($post_ids), $redirect_to));
    exit;
}

// Erfolgsmeldung nach Bulk-Konvertierung
add_action('admin_notices', 'awc_bulk_action_admin_notice');

function awc_bulk_action_admin_notice()
{
    if (!empty($_GET['bulk_awc_converted'])) {
        $count = intval($_GET['bulk_awc_converted']);
        echo "<div class='updated'><p><strong>$count Bilder wurden erfolgreich in WebP konvertiert!</strong></p></div>";
    }
}
// AJAX-Handler für die Massenkonvertierung
add_action('wp_ajax_awc_bulk_convert', 'awc_bulk_convert_images');

function awc_bulk_convert_images()
{
    if (!isset($_POST['image_ids']) || !is_array($_POST['image_ids'])) {
        wp_send_json_error("Keine gültigen Bilder ausgewählt.");
    }

    global $wpdb;
    $converted_count = 0;

    foreach ($_POST['image_ids'] as $post_id) {
        $file_path = get_attached_file($post_id);
        $mime = mime_content_type($file_path);

        $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/tiff', 'image/x-icon'];
        if (!in_array($mime, $allowed_mimes)) {
            continue;
        }

        $webp_path = preg_replace('/\.(jpe?g|png|gif|bmp|tiff|ico)$/i', '.webp', $file_path);

        if (convert_to_webp($file_path, $webp_path, $mime)) {
            // Metadaten aktualisieren
            update_attached_file($post_id, $webp_path);
            update_post_meta($post_id, '_wp_attached_file', str_replace(wp_basename($file_path), wp_basename($webp_path), get_post_meta($post_id, '_wp_attached_file', true)));

            // GUID aktualisieren
            $webp_url = wp_get_attachment_url($post_id);
            $wpdb->update($wpdb->posts, ['guid' => $webp_url], ['ID' => $post_id]);

            $converted_count++;
        }
    }

    wp_send_json_success("$converted_count Bilder erfolgreich konvertiert!");
}
// JavaScript zur AJAX-Steuerung der Massenkonvertierung
add_action('admin_footer-upload.php', 'awc_bulk_convert_js');

function awc_bulk_convert_js()
{
    echo '<script>
    jQuery(document).ready(function($) {
        $("#doaction, #doaction2").click(function(e) {
            var action = $("select[name=\'action\'], select[name=\'action2\']").val();
            if (action === "awc_convert_bulk") {
                e.preventDefault();

                var imageIds = [];
                $("tbody th.check-column input[type=\'checkbox\']:checked").each(function() {
                    imageIds.push($(this).val());
                });

                if (imageIds.length === 0) {
                    alert("Bitte wählen Sie mindestens ein Bild aus.");
                    return;
                }

                var button = $(this);
                button.text("Konvertiere...").prop("disabled", true);

                $.post(ajaxurl, {
                    action: "awc_bulk_convert",
                    image_ids: imageIds
                }, function(response) {
                    button.text("Zu WebP konvertieren").prop("disabled", false);
                    if (response.success) {
                        alert(response.data);
                        location.reload();
                    } else {
                        alert("Fehler bei der Konvertierung.");
                    }
                });
            }
        });
    });
    </script>';
}
