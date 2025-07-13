<?php
/*
Plugin Name: Custom Code Addon
Description: Add global or per-page CSS, JavaScript, and PHP code with conditional injection.
Version: 1.2
Author: Rony
*/

if (!defined('ABSPATH')) exit;

// Add Admin Menu
add_action('admin_menu', function () {
    add_menu_page('Custom Code Addon', 'Custom Code', 'manage_options', 'custom-code-addon', 'render_editor_page', 'dashicons-editor-code', 59);
});

// Register Plugin Settings
add_action('admin_init', function () {
    register_setting('cca_options', 'cca_global_css');
    register_setting('cca_options', 'cca_global_js');
    register_setting('cca_options', 'cca_global_php');
    register_setting('cca_options', 'cca_condition_rules');
});

// Enqueue CodeMirror and styling
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_custom-code-addon') return;

    wp_enqueue_style('cm-css', 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/codemirror.min.css');
    wp_enqueue_script('cm-js', 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/codemirror.min.js', [], null, true);

    $modes = ['css', 'javascript', 'php'];
    foreach ($modes as $mode) {
        wp_enqueue_script("cm-mode-$mode", "https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/$mode/$mode.min.js", ['cm-js'], null, true);
    }

    $addons = ['edit/closetag', 'edit/closebrackets', 'comment/comment', 'hint/show-hint', 'hint/css-hint', 'hint/javascript-hint'];
    foreach ($addons as $addon) {
        wp_enqueue_script("cm-addon-$addon", "https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/addon/$addon.min.js", ['cm-js'], null, true);
    }

    wp_enqueue_style('cm-hint', 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/addon/hint/show-hint.min.css');
    wp_add_inline_style('cm-css', '.CodeMirror{height:300px;font-size:14px;border-radius:6px;}');
});

// Admin Editor Page
function render_editor_page() {
    $css = get_option('cca_global_css', '');
    $js  = get_option('cca_global_js', '');
    $php = get_option('cca_global_php', '');
    $cond = get_option('cca_condition_rules', '');

    echo '<div class="wrap"><h1>Custom Code Addon</h1>';
    echo '<form method="post" action="options.php" id="cca-form">';
    settings_fields('cca_options');

    // CSS
    echo '<h2>Global CSS</h2>';
    echo '<textarea id="cca-css" name="cca_global_css" style="display:none;">' . esc_textarea($css) . '</textarea>';
    echo '<div id="css-box"></div>';

    // JavaScript
    echo '<h2>Global JavaScript</h2>';
    echo '<textarea id="cca-js" name="cca_global_js" style="display:none;">' . esc_textarea($js) . '</textarea>';
    echo '<div id="js-box"></div>';

    // ✅ Global PHP Code
    echo '<h2>Global PHP Code</h2>';
    echo '<textarea id="cca-php" name="cca_global_php" style="display:none;">' . esc_textarea($php) . '</textarea>';
    echo '<div id="php-box"></div>';

    // Conditions
    echo '<h2>Condition Rules</h2>
    <p>Examples: <code>all</code>, <code>post-123</code>, <code>about</code>, <code>category-news</code></p>';
    echo '<textarea name="cca_condition_rules" rows="3" style="width:100%;font-family:monospace;">' . esc_textarea($cond) . '</textarea>';

    submit_button('Save All');
    echo '</form></div>';

    // CodeMirror Init
    echo '<script>
    document.addEventListener("DOMContentLoaded", function() {
        function makeEditor(id, box, mode, hint) {
            const ed = CodeMirror(document.getElementById(box), {
                value: document.getElementById(id).value,
                mode,
                lineNumbers: true,
                autoCloseTags: true,
                autoCloseBrackets: true,
                matchBrackets: true,
                theme: "default",
                extraKeys: {
                    "Ctrl-/": "toggleComment",
                    "Ctrl-Space": "autocomplete"
                },
                hintOptions: {
                    hint: CodeMirror.hint[hint],
                    completeSingle: false
                }
            });
            ed.on("inputRead", function(cm, change) {
                if (change.text[0].match(/[\\w\\.<]/)) cm.showHint();
            });
            document.getElementById("cca-form").addEventListener("submit", function() {
                document.getElementById(id).value = ed.getValue();
            });
        }
        makeEditor("cca-css", "css-box", "css", "css");
        makeEditor("cca-js", "js-box", "javascript", "javascript");
        makeEditor("cca-php", "php-box", "php", "javascript");
    });
    </script>';
}

// Condition Check Function
function cca_should_inject() {
    if (is_admin()) return false;

    $conds = explode(',', get_option('cca_condition_rules', ''));
    $current_id = get_queried_object_id();
    $slug = is_singular() ? get_post_field('post_name', $current_id) : '';

    foreach ($conds as $cond) {
        $cond = trim($cond);
        if (!$cond) continue;
        if ($cond === 'all') return true;
        if (strpos($cond, 'post-') === 0 && intval(substr($cond, 5)) === $current_id) return true;
        if ($cond === $slug) return true;
        if (is_page($cond) || is_single($cond)) return true;
        if (is_category(str_replace('category-', '', $cond))) return true;
    }
    return false;
}

// Output CSS
add_action('wp_head', function () {
    if (!cca_should_inject()) return;
    if ($css = get_option('cca_global_css')) {
        echo '<style>' . wp_strip_all_tags($css) . '</style>';
    }
});

// Output JS
add_action('wp_footer', function () {
    if (!cca_should_inject()) return;
    if ($js = get_option('cca_global_js')) {
        echo '<script>' . wp_strip_all_tags($js) . '</script>';
    }
});

// Output Global PHP
add_action('wp_footer', function () {
    if (!cca_should_inject()) return;
    $php = get_option('cca_global_php', '');
    if (!empty($php)) {
        ob_start();
        try {
            eval($php);
        } catch (Throwable $e) {
            echo '<!-- Global PHP Error: ' . esc_html($e->getMessage()) . ' -->';
        }
        echo ob_get_clean();
    }
});

// Per-page PHP Editor (Metabox)
add_action('add_meta_boxes', function () {
    add_meta_box('cca_custom_php', 'Custom PHP Code (This Page)', function ($post) {
        $val = get_post_meta($post->ID, '_cca_php', true);
        echo '<textarea style="width:100%;height:150px;" name="cca_php">' . esc_textarea($val) . '</textarea>';
    }, null, 'normal', 'default');
});

// Save Per-page PHP
add_action('save_post', function ($post_id) {
    if (isset($_POST['cca_php'])) {
        update_post_meta($post_id, '_cca_php', $_POST['cca_php']);
    }
});

// Output Per-page PHP
add_action('wp_footer', function () {
    if (is_singular()) {
        $code = get_post_meta(get_the_ID(), '_cca_php', true);
        if (!empty($code)) {
            ob_start();
            try {
                eval($code);
            } catch (Throwable $e) {
                echo '<!-- Page PHP Error: ' . esc_html($e->getMessage()) . ' -->';
            }
            echo ob_get_clean();
        }
    }
});
