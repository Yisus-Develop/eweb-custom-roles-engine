<?php
/**
 * Plugin Name: EWEB Custom Roles Engine
 * Description: Professional Role & Capability management engine for WordPress. Create and customize access levels with precision. Part of the EWEB Plugin Suite.
 */
if (!defined('ABSPATH')) exit;

add_action('init', function () {
    if (!class_exists('WP_Role')) return;
    if (!get_role('operador_base')) {
        add_role('operador_base', 'Operador Base', ['read' => true]);
    }
}, 11);






/* =============================
 *  LOG interno (seguro y simple)
 * ============================= */
if (!defined('OPE_LOG_ENABLED')) define('OPE_LOG_ENABLED', false); // ponlo a false para apagar logs
if (!defined('OPE_LOG_MAX_BYTES')) define('OPE_LOG_MAX_BYTES', 1024 * 1024); // 1MB rotación

function ope_log_path() {
    $uploads = wp_upload_dir(null, false);
    $dir = trailingslashit($uploads['basedir']) . 'ope-logs';
    if (!is_dir($dir)) wp_mkdir_p($dir);
    return $dir . '/rol-operador.log';
}
function ope_log($msg, array $ctx = []) {
    if (!OPE_LOG_ENABLED) return;
    $file = ope_log_path();
    // rotación simple
    if (file_exists($file) && filesize($file) > OPE_LOG_MAX_BYTES) {
        @rename($file, $file . '.' . gmdate('Ymd_His'));
    }
    $line = [
        'ts'   => gmdate('c'),
        'uid'  => get_current_user_id(),
        'roles'=> is_user_logged_in() ? (array) wp_get_current_user()->roles : [],
        'uri'  => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '',
        'msg'  => $msg,
        'ctx'  => $ctx,
    ];
    @file_put_contents($file, wp_json_encode($line, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/* === Puntos de observación útiles (no cambian permisos) === */

// 1) Arranque y versiones
add_action('plugins_loaded', function () {
    ope_log('plugins_loaded', [
        'php' => PHP_VERSION,
        'wp'  => get_bloginfo('version'),
        'is_flamingo_active' => (function_exists('is_plugin_active') ? is_plugin_active('flamingo/flamingo.php') : null),
    ]);
}, 1);

// 2) Qué muestra el menú admin respecto a Flamingo
add_action('admin_menu', function () {
    if (!is_admin()) return;
    global $menu, $submenu;
    $found = [];

    // top-level
    if (is_array($menu)) {
        foreach ($menu as $m) {
            if (!is_array($m) || count($m) < 3) continue;
            $found[] = ['place'=>'top','title'=>strip_tags($m[0] ?? ''),'cap'=>$m[1] ?? '','slug'=>$m[2] ?? ''];
        }
    }
    // submenus
    if (is_array($submenu)) {
        foreach ($submenu as $parent_slug => $items) {
            foreach ((array)$items as $sm) {
                if (!is_array($sm) || count($sm) < 3) continue;
                $found[] = ['place'=>'sub','parent'=>$parent_slug,'title'=>strip_tags($sm[0] ?? ''),'cap'=>$sm[1] ?? '','slug'=>$sm[2] ?? ''];
            }
        }
    }
    // filtra solo cosas de Flamingo para no llenar el log
    $fl = array_values(array_filter($found, function($r){
        return isset($r['slug']) && preg_match('~^flamingo(_|$)|flamingo~', (string)$r['slug']);
    }));
    if ($fl) ope_log('admin_menu_scan', ['flamingo_entries'=>$fl]);
}, 9999);

// 3) Log de chequeos de capacidades relacionados con Flamingo (sin recursión)
add_filter('map_meta_cap', function ($caps, $cap, $user_id, $args) {
    // Solo registrar cuando esté relacionado con Flamingo para no saturar
    if ($cap === 'flamingo' || strpos($cap, 'flamingo_') === 0) {
        $u = get_userdata((int)$user_id);
        $roles = $u ? (array)$u->roles : [];
        ope_log('map_meta_cap', ['cap'=>$cap, 'calc'=>$caps, 'uid'=>$user_id, 'roles'=>$roles]);
    }
    return $caps;
}, 50, 4);

// 4) Visor de logs en Herramientas (solo admins)
add_action('admin_menu', function () {
    add_management_page('Logs Operador','Logs Operador','manage_options','ope-logs', function () {
        if (!current_user_can('manage_options')) return;
        $file = ope_log_path();

        // acciones: limpiar/descargar
        if (isset($_POST['ope_clear']) && check_admin_referer('ope_logs')) {
            @unlink($file);
            echo '<div class="notice notice-success"><p>Log borrado.</p></div>';
        }
        if (isset($_GET['ope_download']) && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'ope_logs_dl')) {
            if (file_exists($file)) {
                header('Content-Type: text/plain');
                header('Content-Disposition: attachment; filename="rol-operador.log"');
                readfile($file);
                exit;
            }
        }

        echo '<div class="wrap"><h1>Logs Operador</h1>';
        echo '<p>Ruta: <code>'.esc_html($file).'</code></p>';
        echo '<form method="post">';
        wp_nonce_field('ope_logs');
        echo '<p><button class="button button-secondary" name="ope_clear" value="1">Borrar log</button> ';
        echo '<a class="button" href="'.esc_url(wp_nonce_url(admin_url('tools.php?page=ope-logs&ope_download=1'), 'ope_logs_dl')).'">Descargar</a></p>';
        echo '</form>';

        echo '<h2>Últimas líneas</h2>';
        if (!file_exists($file)) {
            echo '<p><em>No hay log aún.</em></p></div>';
            return;
        }
        // Mostrar las últimas 200 líneas
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $tail  = array_slice((array)$lines, -200);
        echo '<pre style="max-height:60vh;overflow:auto;background:#111;color:#0f0;padding:12px;">'.esc_html(implode("\n",$tail)).'</pre>';
        echo '</div>';
    });
}, 1000);



/* =======================================================
 *  FLAMINGO para operador_base — SAFE v2 (sin 500)
 *  Mapea cualquier cap de Flamingo -> 'edit_pages' solo para ese rol.
 * ======================================================= */

/* Asegura 'edit_pages' en el rol (ancla suave) */
add_action('init', function () {
    if (!class_exists('WP_Role')) return;
    $r = get_role('operador_base');
    if ($r instanceof WP_Role) {
        $r->add_cap('read');
        $r->add_cap('edit_pages');
    }
}, 12);

/* ¿Este user_id es operador_base? */
if (!function_exists('ope_is_operator_user_id')) {
    function ope_is_operator_user_id($user_id) {
        $u = get_userdata((int)$user_id);
        return ($u && in_array('operador_base', (array)$u->roles, true));
    }
}

/* Mapeo global: cualquier 'flamingo_*' -> 'edit_pages' (solo operador_base) */
add_filter('map_meta_cap', function ($caps, $cap, $user_id, $args) {
    if (!ope_is_operator_user_id($user_id)) return $caps;
    if (is_string($cap) && ($cap === 'flamingo' || str_starts_with($cap, 'flamingo_'))) {
        return ['edit_pages'];
    }
    return $caps;
}, 10, 4);

/* (Opcional) Si algo puntual no abre, habilita esto a true para dar 'edit_users' sólo dentro de Flamingo */
if (!defined('OPE_FLAMINGO_GRANT_EDIT_USERS')) define('OPE_FLAMINGO_GRANT_EDIT_USERS', false);

if (!function_exists('ope_is_flamingo_screen')) {
    function ope_is_flamingo_screen() {
        if (!is_admin()) return false;
        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
        return in_array($page, ['flamingo','flamingo_inbound','flamingo_outbound','flamingo_contact'], true);
    }
}

if (OPE_FLAMINGO_GRANT_EDIT_USERS) {
    add_filter('user_has_cap', function ($allcaps, $caps, $args, $user) {
        if (empty($user) || !in_array('operador_base', (array)$user->roles, true)) return $allcaps;
        if (ope_is_flamingo_screen()) {
            $allcaps['edit_users'] = true; // solo dentro de pantallas de Flamingo
        }
        return $allcaps;
    }, 10, 4);
}
/* =======================================================
 *  Puente de menú y sonda para operador_base
 * ======================================================= */

/* 0) Helper: ¿user actual es operador_base? */
if (!function_exists('ope_is_operator_current')) {
    function ope_is_operator_current() {
        $u = wp_get_current_user();
        return ($u && in_array('operador_base', (array)$u->roles, true));
    }
}

/* 1) Menú puente visible para el operador (evita depender del menú nativo) */
add_action('admin_menu', function () {
    if (!ope_is_operator_current()) return;

    $redir = function ($slug) {
        return function () use ($slug) {
            wp_safe_redirect( admin_url('admin.php?page='.$slug) );
            exit;
        };
    };

    add_menu_page(
        'Flamingo (Operador)', 'Flamingo', 'read',
        'fl-bridge', $redir('flamingo_inbound'),
        'dashicons-email', 26
    );
    add_submenu_page('fl-bridge','Mensajes entrantes','Mensajes entrantes','read','fl-bridge-inbox',$redir('flamingo_inbound'));
    add_submenu_page('fl-bridge','Libreta de direcciones','Libreta de direcciones','read','fl-bridge-contacts',$redir('flamingo'));
}, 99);

/* 2) Sonda: cuando entra el operador al admin, guardamos si pasa las caps clave */
add_action('admin_init', function () {
    if (!ope_is_operator_current()) return;
    // chequeos “reales” tal cual los pide Flamingo
    $can_contacts = current_user_can('flamingo_edit_contacts');
    $can_inbound  = current_user_can('flamingo_edit_inbound_messages');
    // guardar en nuestro log interno
    if (function_exists('ope_log')) {
        ope_log('operator_cap_probe', [
            'can_contacts' => $can_contacts ? 1 : 0,
            'can_inbound'  => $can_inbound ? 1 : 0,
        ]);
    }
}, 12);
/* =======================================================
 * LIMPIEZA DE MENÚS PARA EL OPERADOR (sin renombrar nada)
 * – Elimina nuestro puente si existe
 * – Deja solo: Escritorio, Perfil y el Flamingo nativo
 * ======================================================= */

/* Compat: usa los mismos roles destino que ya venimos usando */
if (!defined('OPE_TARGET_ROLES')) define('OPE_TARGET_ROLES', ['operador_formularios','operador_base']);

if (!function_exists('ope_current_user_has_any_role')) {
    function ope_current_user_has_any_role(){
        $u = wp_get_current_user();
        if (!$u) return false;
        foreach (OPE_TARGET_ROLES as $r) if (in_array($r, (array)$u->roles, true)) return true;
        return false;
    }
}

/* 1) Quitar cualquier “puente” previo (fl-bridge / flamingo-bridge) */
add_action('admin_menu', function () {
    if (!ope_current_user_has_any_role()) return;
    foreach (['fl-bridge','flamingo-bridge'] as $slug) {
        remove_menu_page($slug);
        foreach (['fl-bridge-inbox','fl-bridge-contacts','fl-bridge-outbound'] as $sub) {
            remove_submenu_page($slug, $sub);
        }
    }
}, 9998);

/* 2) Whitelist de menús visibles para el operador */
add_action('admin_menu', function () {
    if (!ope_current_user_has_any_role()) return;

    // Top-level que SÍ se quedan:
    $keep_top = [
        'index.php',     // Escritorio
        'profile.php',   // Perfil
        'flamingo',      // Flamingo nativo
         'edit.php?post_type=page',   // Páginas (CORE, URL nativa)
   // 'upload.php',                // Medios (CORE, URL nativa)
    'wpseo_dashboard',           // Yoast SEO (slug más común)
    'wpseo_page_settings',       // (por si tu Yoast usa este slug)
     'edit.php',               // (opcional) Entradas
     'edit.php?post_type=testimonios',
     'edit.php?post_type=logos',  // (opcional) Logos
    ];

    global $menu, $submenu;

    // Ocultar todo lo que no esté en la whitelist
    if (is_array($menu)) {
        foreach ($menu as $m) {
            $slug = $m[2] ?? '';
            if (!$slug) continue;
            if (strpos($slug, 'separator') === 0) continue; // separadores
            if (!in_array($slug, $keep_top, true)) {
                remove_menu_page($slug);
            }
        }
    }

    // Submenús de Flamingo: deja “Libreta…” y “Mensajes entrantes”.
    if (isset($submenu['flamingo'])) {
        foreach ((array)$submenu['flamingo'] as $sm) {
            $slug = $sm[2] ?? '';
            if (!in_array($slug, ['flamingo','flamingo_inbound'], true)) {
                remove_submenu_page('flamingo', $slug);
            }
        }
    }
}, 9999);



/* =======================================================
 *  NOVAFRAMES (nfs) — acceso para el rol operador
 *  Páginas: nfs-generated, nfs-leads
 *  - Concede la capability requerida solo en esas pantallas
 *  - Añade menús "puente" si el original no aparece
 * ======================================================= */
if (!defined('OPE_TARGET_ROLES')) define('OPE_TARGET_ROLES', ['operador_formularios','operador_base']);
if (!defined('OPE_NFS_PAGES'))   define('OPE_NFS_PAGES',   ['nfs-generated','nfs-leads']);

/* Helpers de rol */
if (!function_exists('ope_user_has_any_role_id')) {
    function ope_user_has_any_role_id($user_id){
        $u = get_userdata((int)$user_id);
        if (!$u) return false;
        foreach (OPE_TARGET_ROLES as $r) if (in_array($r, (array)$u->roles, true)) return true;
        return false;
    }
}
if (!function_exists('ope_current_user_has_any_role')) {
    function ope_current_user_has_any_role(){
        $u = wp_get_current_user();
        if (!$u) return false;
        foreach (OPE_TARGET_ROLES as $r) if (in_array($r, (array)$u->roles, true)) return true;
        return false;
    }
}

/* Asegura anclas suaves en el rol (por si acaso) */
add_action('init', function(){
    if (!class_exists('WP_Role')) return;
    foreach (OPE_TARGET_ROLES as $rname){
        $r = get_role($rname);
        if ($r instanceof WP_Role) { $r->add_cap('read'); $r->add_cap('edit_pages'); }
    }
}, 12);

/* Detectar la capability que pide un slug de admin si está en el menú */
if (!function_exists('ope_find_page_cap')) {
    function ope_find_page_cap($slug){
        global $menu, $submenu;
        if (is_array($menu)) {
            foreach ($menu as $m) {
                if (!empty($m[2]) && $m[2] === $slug) return $m[1] ?? '';
            }
        }
        if (is_array($submenu)) {
            foreach ($submenu as $parent => $items) {
                foreach ((array)$items as $sm) {
                    if (!empty($sm[2]) && $sm[2] === $slug) return $sm[1] ?? '';
                }
            }
        }
        return '';
    }
}

/* 1) Conceder capability SOLO cuando se abre nfs-generated o nfs-leads */
add_filter('user_has_cap', function ($allcaps, $caps, $args, $user) {
    if (empty($user) || !ope_user_has_any_role_id($user->ID)) return $allcaps;
    if (!is_admin()) return $allcaps;

    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    if (in_array($page, OPE_NFS_PAGES, true)) {
        $need = ope_find_page_cap($page);
        if ($need) { $allcaps[$need] = true; }
        // anclas suaves por si el plugin usa algo fuerte
        $allcaps['edit_pages']     = true;
        $allcaps['manage_options'] = true;

        if (function_exists('ope_log')) ope_log('nfs_cap_granted', ['page'=>$page, 'need'=>$need ?: 'fallback(manage_options,edit_pages)']);
    }
    return $allcaps;
}, 10, 4);

/* Helper: ¿ya existe un top-level con ese slug? (para evitar duplicados) */
if (!function_exists('ope_menu_has_top_slug')) {
    function ope_menu_has_top_slug($slug){
        global $menu;
        if (!is_array($menu)) return false;
        foreach ($menu as $m) if (!empty($m[2]) && $m[2] === $slug) return true;
        return false;
    }
}

/* 2) Menús "puente" SOLO si el original no aparece como top-level
      (si el plugin los cuelga como submenú y tu limpieza ocultó el padre,
       estos puentes garantizan acceso visible) */
add_action('admin_menu', function () {
    if (!ope_current_user_has_any_role()) return;

    // nfs-generated
    if (!ope_menu_has_top_slug('nfs-generated')) {
        add_menu_page(
            'Generated', 'Generated', 'read',
            'nfs-bridge-generated',
            function(){ wp_safe_redirect( admin_url('admin.php?page=nfs-generated') ); exit; },
            'dashicons-images-alt2', 28
        );
    }
    // nfs-leads
    if (!ope_menu_has_top_slug('nfs-leads')) {
        add_menu_page(
            'Leads', 'Leads', 'read',
            'nfs-bridge-leads',
            function(){ wp_safe_redirect( admin_url('admin.php?page=nfs-leads') ); exit; },
            'dashicons-groups', 29
        );
    }
}, 10000);

/* 3) (Opcional) Log de sonda al entrar al admin con el operador */
add_action('admin_init', function(){
    if (!ope_current_user_has_any_role() || !function_exists('ope_log')) return;
    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    if (in_array($page, OPE_NFS_PAGES, true)) {
        ope_log('nfs_probe', [
            'page' => $page,
            'need' => ope_find_page_cap($page) ?: 'unknown',
        ]);
    }
}, 12);

/* =======================================================
 *  NovaFrames: menús reales (sin bridge) para el operador
 *  - Crea un padre "NovaFrames" (slug: nfs-operator)
 *  - Cuelga submenús que apuntan a los slugs REALES:
 *      admin.php?page=nfs-generated
 *      admin.php?page=nfs-leads
 *  - Elimina cualquier menu "bridge" previo
 * ======================================================= */
add_action('admin_menu', function () {
    // Muestra solo a los roles del operador
    if (!function_exists('ope_current_user_has_any_role') || !ope_current_user_has_any_role()) return;

    // Limpieza de antiguos "bridge"
    remove_menu_page('nfs-bridge-generated');
    remove_menu_page('nfs-bridge-leads');

    // Padre propio (no muestra contenido, solo agrupa)
    add_menu_page(
        'NovaFrames', 'NovaFrames', 'read',
        'nfs-operator', '__return_null',
        'dashicons-art', 28
    );

    // Submenús apuntando a las PÁGINAS REALES del plugin
    add_submenu_page('nfs-operator',
        'Generated', 'Generated', 'read',
        'nfs-generated', '__return_null'
    );
    add_submenu_page('nfs-operator',
        'Leads', 'Leads', 'read',
        'nfs-leads', '__return_null'
    );

    if (function_exists('ope_log')) ope_log('nfs_menu_mounted', ['parent'=>'nfs-operator','children'=>['nfs-generated','nfs-leads']]);
}, 10000);
/* =======================================================
 *  Elementor: menú real para Submissions (sin REST hoy)
 *  - Padre "Forms" (slug: forms-operator)
 *  - Submenú directo al slug real: e-form-submissions
 * ======================================================= */
add_action('admin_menu', function () {
    if (!function_exists('ope_current_user_has_any_role') || !ope_current_user_has_any_role()) return;

    add_menu_page('Forms','Forms','read','forms-operator','__return_null','dashicons-forms',30);
    add_submenu_page('forms-operator','Submissions','Submissions','read','e-form-submissions','__return_null');
}, 10000);

/* Dar anclas suaves SOLO cuando abren e-form-submissions */
add_filter('user_has_cap', function ($allcaps, $caps, $args, $user) {
    if (empty($user) || !function_exists('ope_user_has_any_role_id') || !ope_user_has_any_role_id($user->ID)) return $allcaps;
    if (!is_admin()) return $allcaps;
    if (isset($_GET['page']) && $_GET['page'] === 'e-form-submissions') {
        $allcaps['edit_pages']     = true;   // ancla suave
        $allcaps['manage_options'] = true;   // fallback por si Elementor la pidiera
    }
    return $allcaps;
}, 10, 4);
/* =======================================================
 * Elementor Submissions — permitir GET por REST al operador
 * Cubre /elementor*//*v1*//* con "form" o "submission" en la ruta
 * ======================================================= */

 if (!defined('OPE_TARGET_ROLES')) define('OPE_TARGET_ROLES', ['operador_formularios','operador_base']);

 if (!function_exists('ope_current_user_has_any_role')) {
   function ope_current_user_has_any_role(){
     $u = wp_get_current_user(); if(!$u) return false;
     foreach (OPE_TARGET_ROLES as $r) if (in_array($r, (array)$u->roles, true)) return true;
     return false;
   }
 }
 
 if (!function_exists('ope_esub_route_match')) {
   function ope_esub_route_match($route) {
     if (!is_string($route)) return false;
     $r = strtolower($route);
     // Debe empezar con /elementor o /elementor-pro
     if (strpos($r, '/elementor') !== 0 && strpos($r, '/elementor-pro') !== 0) return false;
     // Y contener "form" o "submission" en cualquier parte (forms, form-submissions, submission, etc.)
     return (strpos($r, 'form') !== false || strpos($r, 'submission') !== false);
   }
 }
 
 /* Envolver permission_callback de rutas REST relevantes (solo lectura para el operador) */
 add_filter('rest_endpoints', function ($endpoints) {
   if (empty($endpoints)) return $endpoints;
 
   $wrap = function ($orig_cb, $route) {
     return function ($request) use ($orig_cb, $route) {
       if (ope_current_user_has_any_role() && ope_esub_route_match($route)) {
         $method = $request->get_method();
         if ($method === 'GET') {
           return true;                 // listar/ver Submissions = OK
         }
         // Para acciones (DELETE/POST/PUT), exige una ancla suave que ya tiene el rol
         if (current_user_can('edit_pages')) {
           return true;
         }
       }
       return is_callable($orig_cb) ? call_user_func($orig_cb, $request) : false;
     };
   };
 
   foreach ($endpoints as $route => $handlers) {
     if (!ope_esub_route_match($route)) continue;
 
     if (isset($handlers['permission_callback'])) {
       $endpoints[$route]['permission_callback'] = $wrap($handlers['permission_callback'], $route);
     } elseif (is_array($handlers)) {
       foreach ($handlers as $i => $h) {
         if (!empty($h['permission_callback'])) {
           $endpoints[$route][$i]['permission_callback'] = $wrap($h['permission_callback'], $route);
         }
       }
     }
   }
   return $endpoints;
 }, 9999);
 
 /* Ancla mínima cuando abren la pantalla (por si el admin page valida cap) */
 add_filter('user_has_cap', function ($allcaps, $caps, $args, $user) {
   if (empty($user) || !ope_current_user_has_any_role()) return $allcaps;
   if (is_admin() && isset($_GET['page']) && $_GET['page'] === 'e-form-submissions') {
     $allcaps['edit_pages']     = true;  // ancla suave
     $allcaps['manage_options'] = true;  // fallback si la pidiera el menú
   }
   return $allcaps;
 }, 10, 4);
 

 add_filter('user_has_cap', function ($allcaps, $caps, $args, $user) {
    if (empty($user)) return $allcaps;
    $roles = defined('OPE_TARGET_ROLES') ? OPE_TARGET_ROLES : ['operador_formularios','operador_base'];
    $is_op = (bool) array_intersect((array)$user->roles, (array)$roles);
    if (!$is_op || !is_admin()) return $allcaps;
  
    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    $whitelist = ['flamingo','flamingo_inbound','flamingo_outbound','flamingo_contact','e-form-submissions','nfs-generated','nfs-leads'];
  
    if (in_array($page, $whitelist, true)) {
      $allcaps['edit_pages'] = true;      // ancla suave solo aquí
      // (si tu plugin pidiera algo más fuerte)
      $allcaps['manage_options'] = true;  // fallback sin efecto fuera de estas pantallas
    }
    return $allcaps;
  }, 9, 4);
  
/* =======================================================
 *  Yoast SEO: acceso para el rol operador
 * ======================================================= */   

/* ============================================
 * SOLO YOAST para varios post types (operador)
 * ============================================ */
