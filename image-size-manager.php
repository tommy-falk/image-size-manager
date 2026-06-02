<?php
/**
 * Plugin Name: Image Size Manager
 * Description: Choose which image sizes WordPress and Divi generate on upload.
 * Version:     1.1.0
 * Author:      Tommy Falk
 * Text Domain: image-size-manager
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'ISM_OPTION', 'ism_disabled_sizes' );
define( 'ISM_TD',     'image-size-manager' );

register_uninstall_hook( __FILE__, 'ism_uninstall' );

add_action( 'plugins_loaded', function () {
    load_plugin_textdomain( ISM_TD, false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

function ism_uninstall() {
    delete_option( ISM_OPTION );
}

/**
 * Skip disabled sizes during upload processing.
 */
add_filter( 'intermediate_image_sizes_advanced', 'ism_filter_sizes', 10, 1 );

function ism_filter_sizes( $sizes ) {
    $disabled = get_option( ISM_OPTION, [] );

    if ( empty( $disabled ) ) {
        return $sizes;
    }

    foreach ( $disabled as $name ) {
        unset( $sizes[ $name ] );
    }

    return $sizes;
}

/**
 * AJAX: return all image attachment IDs.
 */
add_action( 'wp_ajax_ism_get_image_ids', 'ism_ajax_get_image_ids' );

function ism_ajax_get_image_ids() {
    check_ajax_referer( 'ism_regenerate', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        return wp_send_json_error( 'Unauthorized', 403 );
    }

    $ids = get_posts( [
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'post_status'    => 'any',
    ] );

    wp_send_json_success( [ 'ids' => $ids, 'total' => count( $ids ) ] );
}

/**
 * AJAX: calculate disk usage per image size.
 */
add_action( 'wp_ajax_ism_get_stats', 'ism_ajax_get_stats' );

function ism_ajax_get_stats() {
    check_ajax_referer( 'ism_regenerate', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        return wp_send_json_error( 'Unauthorized', 403 );
    }

    $upload_dir = wp_get_upload_dir();
    $base_dir   = $upload_dir['basedir'];

    $attachments = get_posts( [
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'post_status'    => 'any',
    ] );

    // [ size_name => [ count, bytes ] ]
    $stats = [];

    foreach ( $attachments as $id ) {
        $meta = wp_get_attachment_metadata( $id );

        if ( empty( $meta['file'] ) || empty( $meta['sizes'] ) ) {
            continue;
        }

        $dir = trailingslashit( $base_dir . '/' . dirname( $meta['file'] ) );

        foreach ( $meta['sizes'] as $size_name => $size_data ) {
            $path = $dir . $size_data['file'];

            if ( ! isset( $stats[ $size_name ] ) ) {
                $stats[ $size_name ] = [ 'count' => 0, 'bytes' => 0 ];
            }

            if ( file_exists( $path ) ) {
                $stats[ $size_name ]['count']++;
                $stats[ $size_name ]['bytes'] += filesize( $path );
            }
        }
    }

    wp_send_json_success( $stats );
}

/**
 * AJAX: regenerate one attachment.
 */
add_action( 'wp_ajax_ism_regenerate_one', 'ism_ajax_regenerate_one' );

function ism_ajax_regenerate_one() {
    check_ajax_referer( 'ism_regenerate', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        return wp_send_json_error( 'Unauthorized', 403 );
    }

    $id   = (int) ( $_POST['attachment_id'] ?? 0 );
    $file = get_attached_file( $id );

    if ( ! $id || ! $file || ! file_exists( $file ) ) {
        return wp_send_json_error( "File not found for attachment $id" );
    }

    $meta = wp_generate_attachment_metadata( $id, $file );

    if ( is_wp_error( $meta ) ) {
        return wp_send_json_error( $meta->get_error_message() );
    }

    wp_update_attachment_metadata( $id, $meta );
    wp_send_json_success( [ 'id' => $id ] );
}

/**
 * AJAX: delete specific sizes for one attachment and update its metadata.
 */
add_action( 'wp_ajax_ism_delete_sizes_for', 'ism_ajax_delete_sizes_for' );

function ism_ajax_delete_sizes_for() {
    check_ajax_referer( 'ism_regenerate', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        return wp_send_json_error( 'Unauthorized', 403 );
    }

    $id    = (int) ( $_POST['attachment_id'] ?? 0 );
    $sizes = array_map( 'sanitize_key', (array) ( $_POST['sizes'] ?? [] ) );

    if ( ! $id || empty( $sizes ) ) {
        return wp_send_json_error( 'Invalid parameters' );
    }

    $meta = wp_get_attachment_metadata( $id );

    if ( empty( $meta['file'] ) ) {
        return wp_send_json_success( [ 'deleted' => 0 ] );
    }

    $upload_dir = wp_get_upload_dir();
    $dir        = trailingslashit( $upload_dir['basedir'] . '/' . dirname( $meta['file'] ) );
    $real_dir   = realpath( $dir );
    $deleted    = 0;

    foreach ( $sizes as $size_name ) {
        if ( empty( $meta['sizes'][ $size_name ]['file'] ) ) {
            continue;
        }

        $path = $dir . $meta['sizes'][ $size_name ]['file'];

        if ( file_exists( $path ) ) {
            $real_path = realpath( $path );
            if ( $real_path && $real_dir && str_starts_with( $real_path, $real_dir . DIRECTORY_SEPARATOR ) ) {
                unlink( $path );
                $deleted++;
            }
        }

        unset( $meta['sizes'][ $size_name ] );
    }

    wp_update_attachment_metadata( $id, $meta );
    wp_send_json_success( [ 'deleted' => $deleted ] );
}

/**
 * Register settings and admin page.
 */
add_action( 'admin_menu', 'ism_add_menu' );

function ism_add_menu() {
    add_options_page(
        __( 'Image Size Manager', ISM_TD ),
        __( 'Image Sizes', ISM_TD ),
        'manage_options',
        'image-size-manager',
        'ism_render_page'
    );
}

add_action( 'admin_init', 'ism_register_settings' );

function ism_register_settings() {
    register_setting( 'ism_settings', ISM_OPTION, [
        'type'    => 'array',
        'default' => [],
        'sanitize_callback' => 'ism_sanitize_disabled',
    ] );
}

function ism_sanitize_disabled( $input ) {
    if ( ! is_array( $input ) ) {
        return [];
    }
    return array_filter( array_map( 'sanitize_key', $input ) );
}

/**
 * Return all registered image sizes with their dimensions.
 */
function ism_get_all_sizes() {
    global $_wp_additional_image_sizes;

    $sizes = [];

    // WordPress built-in sizes
    $core = [ 'thumbnail', 'medium', 'medium_large', 'large' ];
    foreach ( $core as $name ) {
        $sizes[ $name ] = [
            'label'  => ucwords( str_replace( '_', ' ', $name ) ),
            'width'  => (int) get_option( $name . '_size_w' ),
            'height' => (int) get_option( $name . '_size_h' ),
            'crop'   => (bool) get_option( $name . '_crop' ),
            'source' => 'WordPress',
        ];
    }

    // All additional registered sizes (Divi, plugins, theme)
    if ( ! empty( $_wp_additional_image_sizes ) ) {
        foreach ( $_wp_additional_image_sizes as $name => $data ) {
            $source = 'Other';

            // Try to detect Divi sizes by prefix
            if ( str_starts_with( $name, 'et_' ) || str_starts_with( $name, 'divi' ) ) {
                $source = 'Divi';
            }

            $sizes[ $name ] = [
                'label'  => ucwords( str_replace( [ '_', '-' ], ' ', $name ) ),
                'width'  => (int) ( $data['width'] ?? 0 ),
                'height' => (int) ( $data['height'] ?? 0 ),
                'crop'   => (bool) ( $data['crop'] ?? false ),
                'source' => $source,
            ];
        }
    }

    ksort( $sizes );
    return $sizes;
}

/**
 * Render admin settings page.
 */
function ism_render_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $disabled = get_option( ISM_OPTION, [] );
    $sizes    = ism_get_all_sizes();
    $sources  = array_unique( array_column( $sizes, 'source' ) );
    sort( $sources );
    ?>
    <style>
    :root {
        --ism-primary:        #6e62e5;
        --ism-primary-soft:   #e7e5fc;
        --ism-primary-soft-h: #d8d3f9;
        --ism-primary-5:      color-mix(in srgb, #6e62e5 5%, white);
        --ism-teal:           #0d9488;
        --ism-success:        #16a34a;
        --ism-success-soft:   #dcfce7;
        --ism-danger:         #dc2626;
        --ism-danger-soft:    #fee2e2;
        --ism-danger-soft-h:  #fecaca;
        --ism-warning-text:   #92400e; /* darkened for 4.5:1 contrast on white */
        --ism-text:           #1d2327;
        --ism-text-muted:     #646970; /* 5.74:1 on white — passes AA */
        --ism-text-light:     #8c8f94; /* decorative use only — not for readable text */
        --ism-border:         #dcdcde;
        --ism-border-row:     #e7ebf1;
        --ism-bg-light:       #f9fafb;
        --ism-white:          #fff;
        --ism-radius:         4px;
    }

    /* Shell */
    .ism-shell {
        max-width: 1100px;
        margin: 0;
        padding: 20px 0 40px;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        color: var(--ism-text);
    }

    /* Header */
    .ism-header {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 24px;
        padding: 0 2px;
        flex-wrap: wrap;
    }
    .ism-title {
        font-size: clamp(20px, 3vw, 26px) !important;
        font-weight: 700 !important;
        color: var(--ism-text) !important;
        margin: 0 0 4px !important;
        line-height: 1.2 !important;
        letter-spacing: -0.01em;
    }
    .ism-subtitle {
        font-size: 13px;
        color: var(--ism-text-muted);
        margin: 0;
    }

    /* Buttons */
    .ism-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 14px;
        font-size: 12px;
        font-weight: 600;
        border-radius: var(--ism-radius);
        border: none;
        cursor: pointer;
        transition: background 0.18s ease, color 0.18s ease;
        line-height: 1;
        white-space: nowrap;
        text-decoration: none;
    }
    .ism-btn:disabled { opacity: .55; cursor: not-allowed; }
    .ism-btn:focus-visible {
        outline: 2px solid var(--ism-primary);
        outline-offset: 2px;
    }
    /* Primary = solid teal — one clear CTA per card (Save settings) */
    .ism-btn-primary  { background: var(--ism-teal); color: #fff; }
    .ism-btn-primary:hover:not(:disabled)  { background: #0b7a70; }
    /* Secondary = soft purple (Load stats, Scan disk) */
    .ism-btn-secondary { background: var(--ism-primary-soft); color: var(--ism-primary); }
    .ism-btn-secondary:hover:not(:disabled) { background: var(--ism-primary-soft-h); }
    /* Danger = soft red (Regenerate, Delete) */
    .ism-btn-danger   { background: var(--ism-danger-soft);  color: var(--ism-danger); }
    .ism-btn-danger:hover:not(:disabled)   { background: var(--ism-danger-soft-h); }

    /* Spinner */
    @keyframes ism-spin { to { transform: rotate(360deg); } }
    .ism-spinner {
        display: inline-block;
        width: 12px;
        height: 12px;
        border: 2px solid currentColor;
        border-top-color: transparent;
        border-radius: 50%;
        animation: ism-spin .7s linear infinite;
        flex-shrink: 0;
    }
    @media (prefers-reduced-motion: reduce) {
        .ism-spinner { animation: none; opacity: .5; }
        .ism-toggle-track,
        .ism-toggle-track::before,
        .ism-progress-bar,
        .ism-tab { transition: none !important; }
    }

    /* Cards */
    .ism-card {
        background: var(--ism-white);
        border: 1px solid var(--ism-border);
        border-radius: 12px;
        margin-bottom: 20px;
        overflow: hidden;
    }
    /* Secondary cards (tools) get a lighter treatment */
    .ism-card-secondary {
        background: var(--ism-bg-light);
        border-color: color-mix(in srgb, var(--ism-border) 70%, transparent);
    }
    .ism-card-secondary .ism-card-header {
        background: transparent;
        border-bottom-color: color-mix(in srgb, var(--ism-border) 70%, transparent);
    }

    .ism-card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 18px 22px;
        border-bottom: 1px solid var(--ism-border);
        flex-wrap: wrap;
    }
    .ism-card-title {
        font-size: 15px !important;
        font-weight: 600 !important;
        color: var(--ism-text) !important;
        margin: 0 !important;
    }
    .ism-card-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .ism-card-desc {
        font-size: 13px;
        color: var(--ism-text-muted);
        line-height: 1.55;
        margin: 0 0 18px;
        padding: 0 22px;
    }
    /* Tabs */
    .ism-tabs {
        display: flex;
        gap: 6px;
        padding: 14px 22px 0;
        border-bottom: 1px solid var(--ism-border);
    }
    .ism-tab {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 14px;
        font-size: 13px;
        font-weight: 600;
        color: var(--ism-text-muted);
        background: transparent;
        border: 1px solid transparent;
        border-bottom: none;
        border-radius: 4px 4px 0 0;
        cursor: pointer;
        position: relative;
        bottom: -1px;
        transition: color 0.15s, background 0.15s, border-color 0.15s;
    }
    .ism-tab:hover { color: var(--ism-text); background: var(--ism-bg-light); }
    .ism-tab.is-active {
        color: var(--ism-primary);
        background: var(--ism-white);
        border-color: var(--ism-border);
        border-bottom-color: var(--ism-white);
    }
    .ism-tab-count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 20px;
        height: 18px;
        padding: 0 5px;
        font-size: 11px;
        font-weight: 700;
        border-radius: 9px;
        background: var(--ism-primary-soft);
        color: var(--ism-primary);
    }
    .ism-tab.is-active .ism-tab-count { background: var(--ism-primary); color: #fff; }

    /* Tab panels */
    .ism-tab-panel { display: none; }
    .ism-tab-panel.is-active { display: block; overflow-x: auto; }

    /* Table */
    .ism-table {
        width: 100%;
        border-collapse: collapse;
    }
    .ism-table th {
        background: var(--ism-primary-5);
        color: var(--ism-text-muted);
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .04em;
        padding: 10px 14px;
        text-align: left;
        border-bottom: 1px solid var(--ism-border);
    }
    .ism-table td {
        padding: 10px 14px;
        font-size: 13px;
        line-height: 1.5;
        color: var(--ism-text);
        border-bottom: 1px solid var(--ism-border-row);
        vertical-align: middle;
    }
    .ism-table tbody tr:last-child td { border-bottom: none; }
    .ism-table tbody tr:hover td { background: #fcfdff; }
    .ism-table tbody tr.ism-row-off td:not(:first-child) { opacity: .45; }

    .ism-size-name { font-weight: 600; font-size: 13px; }
    .ism-dims      { color: var(--ism-text-muted); font-size: 12px; font-variant-numeric: tabular-nums; }

    /* Badge */
    .ism-badge {
        display: inline-flex;
        align-items: center;
        padding: 2px 8px;
        border-radius: var(--ism-radius);
        font-size: 11px;
        font-weight: 600;
        line-height: 1.4;
    }
    .ism-badge-yes { background: var(--ism-success-soft); color: var(--ism-success); }

    /* Stat cells */
    .ism-stat-pending { color: var(--ism-text-muted) !important; } /* #646970 = 5.74:1 on white ✓ */
    .ism-stat-bytes   { font-variant-numeric: tabular-nums; font-size: 12px; }
    .ism-stat-files   { font-variant-numeric: tabular-nums; font-size: 12px; }
    .ism-stat-high    { color: var(--ism-danger) !important; font-weight: 600; } /* #dc2626 = 5.1:1 ✓ */
    .ism-stat-mid     { color: var(--ism-warning-text) !important; } /* #92400e = 6.1:1 ✓ */

    /* Toggle switch */
    .ism-toggle-wrap { display: inline-flex; align-items: center; cursor: pointer; }
    .ism-toggle-input {
        position: absolute;
        opacity: 0;
        width: 0;
        height: 0;
        pointer-events: none;
    }
    .ism-toggle-track {
        display: block;
        width: 34px;
        height: 19px;
        border-radius: 10px;
        background: #c3c4c7;
        position: relative;
        transition: background 0.2s ease;
        flex-shrink: 0;
    }
    .ism-toggle-track::before {
        content: '';
        position: absolute;
        width: 13px;
        height: 13px;
        border-radius: 50%;
        background: #fff;
        top: 3px;
        left: 3px;
        transition: transform 0.2s ease;
        box-shadow: 0 1px 2px rgba(0,0,0,.25);
    }
    .ism-toggle-input:checked + .ism-toggle-track { background: var(--ism-primary); }
    .ism-toggle-input:checked + .ism-toggle-track::before { transform: translateX(15px); }
    .ism-toggle-input:focus-visible + .ism-toggle-track { outline: 2px solid var(--ism-primary); outline-offset: 2px; }

    /* Progress */
    .ism-progress-track {
        height: 8px;
        background: var(--ism-border);
        border-radius: 4px;
        overflow: hidden;
        margin-bottom: 10px;
    }
    .ism-progress-bar {
        height: 100%;
        width: 0;
        background: var(--ism-primary);
        border-radius: 4px;
        transition: width .25s ease;
    }
    .ism-progress-bar.done { background: var(--ism-success); }
    .ism-regen-status {
        font-size: 13px;
        color: var(--ism-text-muted);
        margin: 0 0 8px;
    }
    .ism-regen-errors {
        margin: 8px 0 0;
        padding: 0;
        list-style: none;
        font-size: 12px;
        color: var(--ism-danger);
    }
    .ism-regen-errors li {
        padding: 3px 0;
        border-bottom: 1px solid var(--ism-danger-soft);
    }

    /* Stats summary cards */
    .ism-stat-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 14px;
        padding: 20px 22px;
        border-top: 1px solid var(--ism-border);
    }
    .ism-stat-card {
        background: var(--ism-bg-light);
        border: 1px solid var(--ism-border);
        border-radius: 8px;
        padding: 14px 16px;
        text-align: center;
    }
    .ism-stat-card-label {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .04em;
        color: var(--ism-text-muted);
        margin-bottom: 6px;
    }
    .ism-stat-card-value {
        font-size: 20px;
        font-weight: 700;
        color: var(--ism-primary);
        font-variant-numeric: tabular-nums;
    }
    .ism-stat-card-value.total { color: var(--ism-text); }
    .ism-stat-card-value.danger { color: var(--ism-danger); }

    /* Notice */
    .ism-notice {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px 14px;
        border-radius: var(--ism-radius);
        font-size: 13px;
        margin: 0 22px 0;
    }
    .ism-notice-success { background: #ecfdf5; color: #065f46; border-left: 3px solid #16a34a; }
    .ism-notice-error   { background: #fef2f2; color: #991b1b; border-left: 3px solid #dc2626; }

    @media (max-width: 782px) {
        .ism-card-header { flex-direction: column; align-items: flex-start; }
        .ism-header { flex-direction: column; align-items: flex-start; }
        .ism-stat-cards { grid-template-columns: 1fr 1fr; }
    }
    </style>

    <div class="wrap">
    <div class="ism-shell">

        <div class="ism-header">
            <div>
                <h1 class="ism-title"><?php esc_html_e( 'Image Size Manager', ISM_TD ); ?></h1>
                <p class="ism-subtitle"><?php esc_html_e( 'Control which sizes are generated on upload. Disabled sizes are skipped for future uploads.', ISM_TD ); ?></p>
            </div>
        </div>

        <?php if ( isset( $_GET['settings-updated'] ) ) : ?>
        <div class="ism-notice ism-notice-success" style="margin-bottom:16px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            <?php esc_html_e( 'Settings saved.', ISM_TD ); ?>
        </div>
        <?php endif; ?>

        <!-- Sizes card -->
        <form method="post" action="options.php" id="ism-form">
            <?php settings_fields( 'ism_settings' ); ?>
            <input type="hidden" name="ism_submitted" value="1">

            <div class="ism-card">
                <div class="ism-card-header">
                    <h2 class="ism-card-title"><?php esc_html_e( 'Image sizes', ISM_TD ); ?></h2>
                    <div class="ism-card-actions">
                        <button type="button" id="ism-stats-btn" class="ism-btn ism-btn-secondary"
                                data-label="<?php echo esc_attr__( 'Load disk stats', ISM_TD ); ?>"
                                data-reload-label="<?php echo esc_attr__( 'Reload disk stats', ISM_TD ); ?>">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true" focusable="false"><rect x="18" y="3" width="4" height="18"/><rect x="10" y="8" width="4" height="13"/><rect x="2" y="13" width="4" height="8"/></svg>
                            <?php esc_html_e( 'Load disk stats', ISM_TD ); ?>
                        </button>
                        <button type="submit" class="ism-btn ism-btn-primary">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true" focusable="false"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17,21 17,13 7,13 7,21"/><polyline points="7,3 7,8 15,8"/></svg>
                            <?php esc_html_e( 'Save settings', ISM_TD ); ?>
                        </button>
                    </div>
                </div>

                <div class="ism-tabs">
                    <?php foreach ( $sources as $i => $source ) :
                        $count = count( array_filter( $sizes, fn( $s ) => $s['source'] === $source ) );
                    ?>
                    <button type="button" class="ism-tab <?php echo $i === 0 ? 'is-active' : ''; ?>"
                            data-tab="<?php echo esc_attr( $source ); ?>">
                        <?php echo esc_html( $source ); ?>
                        <span class="ism-tab-count"><?php echo $count; ?></span>
                    </button>
                    <?php endforeach; ?>
                </div>

                <?php foreach ( $sources as $i => $source ) : ?>
                <div class="ism-tab-panel <?php echo $i === 0 ? 'is-active' : ''; ?>"
                     data-panel="<?php echo esc_attr( $source ); ?>">
                    <table class="ism-table">
                        <thead>
                            <tr>
                                <th style="width:60px;"><?php esc_html_e( 'Active', ISM_TD ); ?></th>
                                <th><?php esc_html_e( 'Size name', ISM_TD ); ?></th>
                                <th><?php esc_html_e( 'Dimensions', ISM_TD ); ?></th>
                                <th style="width:68px;"><?php esc_html_e( 'Crop', ISM_TD ); ?></th>
                                <th style="width:72px;"><?php esc_html_e( 'Files', ISM_TD ); ?></th>
                                <th style="width:110px;"><?php esc_html_e( 'Disk usage', ISM_TD ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $sizes as $name => $size ) :
                            if ( $size['source'] !== $source ) continue;
                            $enabled = ! in_array( $name, $disabled, true );
                            $w = $size['width']; $h = $size['height'];
                            $dims = ( $w || $h ) ? "{$w} × {$h} px" : '—';
                        ?>
                        <tr data-size="<?php echo esc_attr( $name ); ?>" class="<?php echo $enabled ? '' : 'ism-row-off'; ?>">
                            <td>
                                <label class="ism-toggle-wrap">
                                    <input
                                        type="checkbox"
                                        class="ism-toggle-input"
                                        name="<?php echo esc_attr( ISM_OPTION ); ?>_enabled[]"
                                        value="<?php echo esc_attr( $name ); ?>"
                                        id="ism_<?php echo esc_attr( $name ); ?>"
                                        <?php checked( $enabled ); ?>
                                    >
                                    <span class="ism-toggle-track"></span>
                                </label>
                            </td>
                            <td>
                                <label for="ism_<?php echo esc_attr( $name ); ?>" class="ism-size-name" style="cursor:pointer;">
                                    <?php echo esc_html( $name ); ?>
                                </label>
                            </td>
                            <td class="ism-dims"><?php echo esc_html( $dims ); ?></td>
                            <td><?php echo $size['crop'] ? '<span class="ism-badge ism-badge-yes">' . esc_html__( 'Yes', ISM_TD ) . '</span>' : ''; ?></td>
                            <td class="ism-stat-files ism-stat-pending">—</td>
                            <td class="ism-stat-bytes ism-stat-pending">—</td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endforeach; ?>

                <div id="ism-stat-summary" style="display:none;"></div>
            </div>
        </form>

        <!-- Regenerate card -->
        <div class="ism-card ism-card-secondary">
            <div class="ism-card-header">
                <h2 class="ism-card-title"><?php esc_html_e( 'Regenerate thumbnails', ISM_TD ); ?></h2>
            </div>
            <p class="ism-card-desc" style="padding-top:16px;">
                <?php esc_html_e( 'Re-creates all image sizes for every image in the media library using your current settings above. Disabled sizes are skipped — enabled sizes will be created or replaced.', ISM_TD ); ?>
            </p>
            <div style="padding: 0 22px 20px;">
                <button id="ism-regen-btn" class="ism-btn ism-btn-danger">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true" focusable="false"><path d="M1 4v6h6"/><path d="M23 20v-6h-6"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/></svg>
                    <?php esc_html_e( 'Regenerate all thumbnails', ISM_TD ); ?>
                </button>

                <div id="ism-regen-progress" style="display:none;margin-top:20px;max-width:560px;">
                    <div class="ism-progress-track">
                        <div id="ism-regen-bar" class="ism-progress-bar"></div>
                    </div>
                    <p id="ism-regen-status" class="ism-regen-status" aria-live="polite"></p>
                    <ul id="ism-regen-errors" class="ism-regen-errors" role="alert" aria-live="assertive"></ul>
                </div>
            </div>
        </div>

        <!-- Clean up card -->
        <div class="ism-card ism-card-secondary">
            <div class="ism-card-header">
                <h2 class="ism-card-title"><?php esc_html_e( 'Clean up old files', ISM_TD ); ?></h2>
                <div class="ism-card-actions">
                    <button type="button" id="ism-cleanup-load-btn" class="ism-btn ism-btn-secondary"
                            data-label="<?php echo esc_attr__( 'Scan disk', ISM_TD ); ?>"
                            data-reload-label="<?php echo esc_attr__( 'Re-scan disk', ISM_TD ); ?>">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true" focusable="false"><rect x="18" y="3" width="4" height="18"/><rect x="10" y="8" width="4" height="13"/><rect x="2" y="13" width="4" height="8"/></svg>
                        <?php esc_html_e( 'Scan disk', ISM_TD ); ?>
                    </button>
                </div>
            </div>
            <p class="ism-card-desc" style="padding-top:16px;">
                <?php esc_html_e( "Scan the media library to see which sizes exist on disk, then choose which ones to permanently delete. Metadata is updated automatically so WordPress doesn't reference missing files.", ISM_TD ); ?>
            </p>

            <div id="ism-cleanup-body" style="display:none;">
                <div style="padding: 0 22px 6px;">
                    <p id="ism-cleanup-none" style="display:none;color:var(--ism-text-muted);font-size:13px;"><?php esc_html_e( 'No generated size files found on disk.', ISM_TD ); ?></p>
                </div>
                <table id="ism-cleanup-table" class="ism-table" style="display:none;">
                    <thead>
                        <tr>
                            <th style="width:44px;">
                                <label class="ism-toggle-wrap" title="<?php echo esc_attr__( 'Select all', ISM_TD ); ?>">
                                    <input type="checkbox" class="ism-toggle-input" id="ism-cleanup-all">
                                    <span class="ism-toggle-track"></span>
                                </label>
                            </th>
                            <th><?php esc_html_e( 'Size name', ISM_TD ); ?></th>
                            <th><?php esc_html_e( 'Files on disk', ISM_TD ); ?></th>
                            <th><?php esc_html_e( 'Disk usage', ISM_TD ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="ism-cleanup-rows"></tbody>
                </table>

                <div id="ism-cleanup-actions" style="display:none;padding:16px 22px;border-top:1px solid var(--ism-border);align-items:center;gap:12px;flex-wrap:wrap;">
                    <button type="button" id="ism-cleanup-run-btn" class="ism-btn ism-btn-danger" disabled>
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true" focusable="false"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                        <?php esc_html_e( 'Delete selected files', ISM_TD ); ?>
                    </button>
                    <span id="ism-cleanup-selection-label" style="font-size:12px;color:var(--ism-text-muted);"></span>
                </div>

                <div id="ism-cleanup-progress" style="display:none;padding:0 22px 20px;">
                    <div class="ism-progress-track">
                        <div id="ism-cleanup-bar" class="ism-progress-bar"></div>
                    </div>
                    <p id="ism-cleanup-status" class="ism-regen-status" aria-live="polite"></p>
                    <ul id="ism-cleanup-errors" class="ism-regen-errors" role="alert" aria-live="assertive"></ul>
                </div>
            </div>
        </div>

    </div><!-- .ism-shell -->
    </div><!-- .wrap -->

    <script>
    (function () {
        const ismAjax = {
            url:   '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',
            nonce: '<?php echo esc_js( wp_create_nonce( 'ism_regenerate' ) ); ?>',
        };

        const ismL10n = <?php echo wp_json_encode( [
            'calculating'    => __( 'Calculating…',                                                                    ISM_TD ),
            'fetchingImages' => __( 'Fetching images…',                                                                ISM_TD ),
            'fetchingList'   => __( 'Fetching image list…',                                                            ISM_TD ),
            'scanning'       => __( 'Scanning…',                                                                       ISM_TD ),
            'noImages'       => __( 'No images found in the media library.',                                           ISM_TD ),
            'networkError'   => __( 'network error',                                                                   ISM_TD ),
            'regenConfirm'   => __( 'This will regenerate thumbnails for all images in the media library. Continue?', ISM_TD ),
            /* translators: %d = processed, %d = total */
            'progress'       => __( '%d / %d images…',                                                                ISM_TD ),
            /* translators: %d = count */
            'regenDone'      => __( 'Done! %d images processed',                                                       ISM_TD ),
            /* translators: %d = error count */
            'regenErrNote'   => __( '— %d error(s), see list below',                                                  ISM_TD ),
            /* translators: %d = deleted count */
            'cleanupDone'    => __( 'Done! %d files deleted',                                                          ISM_TD ),
            /* translators: %d = error count */
            'cleanupErrNote' => __( '— %d error(s)',                                                                   ISM_TD ),
            /* translators: %1$d = size count, %2$s = comma-separated size names */
            'deleteConfirm'  => __( "Permanently delete all files for %1\$d size(s)?\n\n%2\$s\n\nThis cannot be undone.", ISM_TD ),
            /* translators: %d = selected count */
            'sizesSelected'  => __( '%d size(s) selected',                                                             ISM_TD ),
            'statLabelDisk'  => __( 'Total disk usage',                                                                ISM_TD ),
            'statLabelFiles' => __( 'Generated files',                                                                 ISM_TD ),
            'statLabelSizes' => __( 'Active sizes',                                                                    ISM_TD ),
        ] ); ?>;

        function post(action, extra = {}) {
            return fetch(ismAjax.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action, nonce: ismAjax.nonce, ...extra }),
            }).then(r => r.json());
        }

        function formatBytes(bytes) {
            if (!bytes) return '0 B';
            const u = ['B','KB','MB','GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(1024));
            return (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0) + ' ' + u[i];
        }

        // sprintf-lite: handles %d/%s (sequential) and %1$d/%2$s (positional)
        function ismFmt(tpl, ...args) {
            let i = 0;
            return tpl
                .replace(/%(\d+)\$[ds]/g, (_, n) => args[parseInt(n, 10) - 1])
                .replace(/%[ds]/g, () => args[i++]);
        }

        /* --- Tabs --- */
        document.querySelectorAll('.ism-tab').forEach(tab => {
            tab.addEventListener('click', function () {
                document.querySelectorAll('.ism-tab').forEach(t => t.classList.remove('is-active'));
                document.querySelectorAll('.ism-tab-panel').forEach(p => p.classList.remove('is-active'));
                this.classList.add('is-active');
                document.querySelector(`.ism-tab-panel[data-panel="${this.dataset.tab}"]`).classList.add('is-active');
            });
        });

        /* --- Toggle row dim --- */
        document.querySelectorAll('.ism-toggle-input').forEach(cb => {
            cb.addEventListener('change', function () {
                this.closest('tr').classList.toggle('ism-row-off', !this.checked);
            });
        });

        /* --- Stats --- */
        document.getElementById('ism-stats-btn').addEventListener('click', async function () {
            const origHTML = this.innerHTML;
            this.disabled  = true;
            this.innerHTML = '<span class="ism-spinner"></span> ' + ismL10n.calculating;

            const res = await post('ism_get_stats');

            this.disabled  = false;
            this.innerHTML = origHTML.replace(this.dataset.label, this.dataset.reloadLabel);

            if (!res.success) { alert('Error: ' + res.data); return; }

            const stats      = res.data;
            const totalBytes = Object.values(stats).reduce((s, x) => s + x.bytes, 0);
            const totalFiles = Object.values(stats).reduce((s, x) => s + x.count, 0);

            document.querySelectorAll('tr[data-size]').forEach(row => {
                const s     = stats[row.dataset.size];
                const fCell = row.querySelector('.ism-stat-files');
                const bCell = row.querySelector('.ism-stat-bytes');
                fCell.classList.remove('ism-stat-pending');
                bCell.classList.remove('ism-stat-pending');

                if (s && s.count) {
                    fCell.textContent = s.count.toLocaleString();
                    bCell.textContent = formatBytes(s.bytes);
                    bCell.className   = 'ism-stat-bytes ' + (s.bytes > 10*1024*1024 ? 'ism-stat-high' : s.bytes > 1024*1024 ? 'ism-stat-mid' : '');
                } else {
                    fCell.textContent = '0';
                    bCell.textContent = '0 B';
                    bCell.style.color = 'var(--ism-text-light)';
                }
            });

            const summary = document.getElementById('ism-stat-summary');
            summary.style.display = 'block';
            summary.innerHTML = `
                <div class="ism-stat-cards">
                    <div class="ism-stat-card">
                        <div class="ism-stat-card-label">${ismL10n.statLabelDisk}</div>
                        <div class="ism-stat-card-value ${totalBytes > 100*1024*1024 ? 'danger' : 'total'}">${formatBytes(totalBytes)}</div>
                    </div>
                    <div class="ism-stat-card">
                        <div class="ism-stat-card-label">${ismL10n.statLabelFiles}</div>
                        <div class="ism-stat-card-value total">${totalFiles.toLocaleString()}</div>
                    </div>
                    <div class="ism-stat-card">
                        <div class="ism-stat-card-label">${ismL10n.statLabelSizes}</div>
                        <div class="ism-stat-card-value">${Object.keys(stats).length}</div>
                    </div>
                </div>`;
        });

        /* --- Regenerate --- */
        document.getElementById('ism-regen-btn').addEventListener('click', async function () {
            if (!confirm(ismL10n.regenConfirm)) return;

            const btn      = this;
            const progress = document.getElementById('ism-regen-progress');
            const bar      = document.getElementById('ism-regen-bar');
            const status   = document.getElementById('ism-regen-status');
            const errList  = document.getElementById('ism-regen-errors');
            const origHTML = btn.innerHTML;

            btn.disabled  = true;
            btn.innerHTML = '<span class="ism-spinner"></span> ' + ismL10n.fetchingImages;
            errList.innerHTML = '';
            bar.style.width   = '0';
            bar.classList.remove('done');
            progress.style.display = 'block';
            status.textContent = '';

            const res = await post('ism_get_image_ids');
            if (!res.success) {
                status.textContent = 'Error: ' + res.data;
                btn.disabled = false; btn.innerHTML = origHTML; return;
            }

            const ids   = res.data.ids;
            const total = res.data.total;

            if (total === 0) {
                progress.style.display = 'none';
                btn.disabled  = false;
                btn.innerHTML = origHTML;
                status.textContent = '';
                alert(ismL10n.noImages);
                return;
            }

            let done = 0, errors = 0;
            status.textContent = ismFmt(ismL10n.progress, 0, total);

            for (const id of ids) {
                try {
                    const d = await post('ism_regenerate_one', { attachment_id: id });
                    if (!d.success) {
                        errors++;
                        const li = document.createElement('li');
                        li.textContent = `ID ${id}: ${d.data}`;
                        errList.appendChild(li);
                    }
                } catch (e) {
                    errors++;
                    const li = document.createElement('li');
                    li.textContent = `ID ${id}: ${ismL10n.networkError}`;
                    errList.appendChild(li);
                }
                done++;
                bar.style.width    = Math.round(done / total * 100) + '%';
                status.textContent = ismFmt(ismL10n.progress, done, total);
            }

            bar.classList.add('done');
            bar.style.width    = '100%';
            const errNote      = errors ? ' ' + ismFmt(ismL10n.regenErrNote, errors) : '';
            status.textContent = ismFmt(ismL10n.regenDone, done) + errNote;
            btn.disabled       = false;
            btn.innerHTML      = origHTML;
        });

        /* --- Clean up --- */
        (function () {
            const loadBtn   = document.getElementById('ism-cleanup-load-btn');
            const body      = document.getElementById('ism-cleanup-body');
            const noneMsg   = document.getElementById('ism-cleanup-none');
            const table     = document.getElementById('ism-cleanup-table');
            const tbody     = document.getElementById('ism-cleanup-rows');
            const actions   = document.getElementById('ism-cleanup-actions');
            const runBtn    = document.getElementById('ism-cleanup-run-btn');
            const selLabel  = document.getElementById('ism-cleanup-selection-label');
            const progress  = document.getElementById('ism-cleanup-progress');
            const bar       = document.getElementById('ism-cleanup-bar');
            const status    = document.getElementById('ism-cleanup-status');
            const errList   = document.getElementById('ism-cleanup-errors');
            const selectAll = document.getElementById('ism-cleanup-all');

            let statsCache = null;

            function updateSelection() {
                const checked = tbody.querySelectorAll('.ism-cleanup-cb:checked');
                runBtn.disabled    = checked.length === 0;
                selLabel.textContent = checked.length ? ismFmt(ismL10n.sizesSelected, checked.length) : '';
            }

            selectAll.addEventListener('change', function () {
                tbody.querySelectorAll('.ism-cleanup-cb').forEach(cb => cb.checked = this.checked);
                updateSelection();
            });

            loadBtn.addEventListener('click', async function () {
                const origHTML = this.innerHTML;
                this.disabled  = true;
                this.innerHTML = '<span class="ism-spinner"></span> ' + ismL10n.scanning;

                const res = await post('ism_get_stats');

                this.disabled  = false;
                this.innerHTML = origHTML.replace(this.dataset.label, this.dataset.reloadLabel);

                if (!res.success) { alert('Error: ' + res.data); return; }

                statsCache = res.data;
                const withFiles = Object.entries(statsCache).filter(([, s]) => s.count > 0);

                body.style.display     = 'block';
                tbody.innerHTML        = '';
                selectAll.checked      = false;
                progress.style.display = 'none';

                if (withFiles.length === 0) {
                    noneMsg.style.display = 'block';
                    table.style.display   = 'none';
                    actions.style.display = 'none';
                    return;
                }

                noneMsg.style.display = 'none';
                table.style.display   = '';
                actions.style.display = 'flex';

                withFiles
                    .sort((a, b) => b[1].bytes - a[1].bytes)
                    .forEach(([name, s]) => {
                        const tr = document.createElement('tr');
                        tr.dataset.size = name;

                        const tdCb  = document.createElement('td');
                        const lbl   = document.createElement('label');
                        lbl.className = 'ism-toggle-wrap';
                        const cb    = document.createElement('input');
                        cb.type      = 'checkbox';
                        cb.className = 'ism-toggle-input ism-cleanup-cb';
                        const trk   = document.createElement('span');
                        trk.className = 'ism-toggle-track';
                        lbl.appendChild(cb);
                        lbl.appendChild(trk);
                        tdCb.appendChild(lbl);

                        const tdName   = document.createElement('td');
                        const nameSpan = document.createElement('span');
                        nameSpan.className   = 'ism-size-name';
                        nameSpan.textContent = name;
                        tdName.appendChild(nameSpan);

                        const tdCount       = document.createElement('td');
                        tdCount.className   = 'ism-dims';
                        tdCount.textContent = s.count.toLocaleString();

                        const tdBytes       = document.createElement('td');
                        tdBytes.className   = 'ism-stat-bytes ' + (s.bytes > 10*1024*1024 ? 'ism-stat-high' : s.bytes > 1024*1024 ? 'ism-stat-mid' : '');
                        tdBytes.textContent = formatBytes(s.bytes);

                        tr.appendChild(tdCb);
                        tr.appendChild(tdName);
                        tr.appendChild(tdCount);
                        tr.appendChild(tdBytes);
                        tbody.appendChild(tr);
                    });

                tbody.querySelectorAll('.ism-cleanup-cb').forEach(cb => cb.addEventListener('change', updateSelection));
                updateSelection();
            });

            runBtn.addEventListener('click', async function () {
                const selectedSizes = Array.from(tbody.querySelectorAll('.ism-cleanup-cb:checked'))
                    .map(cb => cb.closest('tr').dataset.size);

                if (!selectedSizes.length) return;
                if (!confirm(ismFmt(ismL10n.deleteConfirm, selectedSizes.length, selectedSizes.join(', ')))) return;

                runBtn.disabled   = true;
                loadBtn.disabled  = true;
                errList.innerHTML = '';
                bar.style.width   = '0';
                bar.classList.remove('done');
                progress.style.display = 'block';
                status.textContent = ismL10n.fetchingList;

                const idsRes = await post('ism_get_image_ids');
                if (!idsRes.success) {
                    status.textContent = 'Error: ' + idsRes.data;
                    runBtn.disabled = false; loadBtn.disabled = false; return;
                }

                const ids   = idsRes.data.ids;
                const total = idsRes.data.total;
                let done = 0, deleted = 0, errors = 0;

                status.textContent = ismFmt(ismL10n.progress, 0, total);

                for (const id of ids) {
                    try {
                        const body2 = new URLSearchParams({ action: 'ism_delete_sizes_for', nonce: ismAjax.nonce, attachment_id: id });
                        selectedSizes.forEach(s => body2.append('sizes[]', s));
                        const r = await fetch(ismAjax.url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body2 });
                        const d = await r.json();
                        if (d.success) {
                            deleted += d.data.deleted;
                        } else {
                            errors++;
                            const li = document.createElement('li');
                            li.textContent = `ID ${id}: ${d.data}`;
                            errList.appendChild(li);
                        }
                    } catch (e) {
                        errors++;
                        const li = document.createElement('li');
                        li.textContent = `ID ${id}: ${ismL10n.networkError}`;
                        errList.appendChild(li);
                    }
                    done++;
                    bar.style.width    = Math.round(done / total * 100) + '%';
                    status.textContent = ismFmt(ismL10n.progress, done, total);
                }

                bar.classList.add('done');
                bar.style.width = '100%';
                const errNote   = errors ? ' ' + ismFmt(ismL10n.cleanupErrNote, errors) : '';
                status.textContent = ismFmt(ismL10n.cleanupDone, deleted) + errNote;

                runBtn.disabled  = false;
                loadBtn.disabled = false;

                // Reload stats into table
                loadBtn.click();
            });
        })();

        /* --- Form: convert checked → disabled list on submit --- */
        document.getElementById('ism-form').addEventListener('submit', function (e) {
            e.preventDefault();
            const form       = this;
            const checkboxes = Array.from(form.querySelectorAll('input.ism-toggle-input'));
            const disabledNames = checkboxes.filter(cb => !cb.checked).map(cb => cb.value);

            form.querySelectorAll('input[name="<?php echo esc_js( ISM_OPTION ); ?>[]"]').forEach(el => el.remove());

            const values = disabledNames.length ? disabledNames : [''];
            values.forEach(val => {
                const input = document.createElement('input');
                input.type  = 'hidden';
                input.name  = '<?php echo esc_js( ISM_OPTION ); ?>[]';
                input.value = val;
                form.appendChild(input);
            });

            checkboxes.forEach(cb => cb.name = '');
            form.submit();
        });
    })();
    </script>
    <?php
}
