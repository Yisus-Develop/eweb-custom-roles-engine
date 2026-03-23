# Mini‑plugin: Rol Operador Base (Mars)

Este mini‑plugin crea y gobierna un **perfil operativo** con acceso **solo** a pantallas puntuales del admin de WordPress. Incluye limpieza de menús, mapeo de _capabilities_ para Flamingo, accesos a NovaFrames (NFS) y soporte de solo‑lectura para **Elementor Submissions** vía REST. Trae un **logger** interno opcional.

---

## 📦 Qué hace (resumen)
- Crea el rol `operador_base` (también soporta `operador_formularios` si existe).
- Deja visibles (para ese rol): **Escritorio, Perfil, Flamingo, NovaFrames, Forms**.
- **Flamingo**: mapea todas las `flamingo_*` a `edit_pages` solo en sus pantallas.
- **NovaFrames (NFS)**: añade acceso a `admin.php?page=nfs-generated` y `admin.php?page=nfs-leads`.
- **Elementor Submissions**: habilita lectura de la tabla usando REST (`/elementor*/v*/...`) y menús.

> Principio: **mínimo privilegio**. Las _caps_ “fuertes” se otorgan **solo** dentro de las pantallas whitelisteadas.

---

## ✅ Requisitos
- WordPress ≥ 6.x
- PHP ≥ 8.0 (funciona en 8.3)
- Plugins opcionales a integrar: **Flamingo**, **Elementor / Elementor Pro**, **NovaFrames/NFS**

---

## 🔧 Instalación
1. Carpeta: `wp-content/plugins/rol-operador-base/`
2. Archivo principal: `rol-operador-base.php` (el que ya estás usando).
3. Activar el plugin en **Plugins** → **Activar**.
4. (Opcional) Crear usuario con el rol **Operador Base**.

---

## 🧭 Menú para el operador
El plugin limpia el menú y deja:
- **Escritorio** (`index.php`)
- **Perfil** (`profile.php`)
- **Flamingo** (nativo): `flamingo`, con submenú `flamingo_inbound`
- **NovaFrames** (padre propio `nfs-operator`): submenús reales `nfs-generated`, `nfs-leads`
- **Forms** (padre propio `forms-operator`): submenú real `e-form-submissions`

> Si otro plugin oculta menús, estos se añaden con **prioridad alta (10000)** para que sigan visibles.

---

## 🔐 Permisos y capacidades

### Flamingo
- Cualquier meta‑cap que empiece por `flamingo_` se **mapea a `edit_pages`** dentro de esas pantallas
  usando el filtro global `map_meta_cap`.
- Switch de emergencia: `OPE_FLAMINGO_GRANT_EDIT_USERS` (por defecto `false`) para casos antiguos que
  exijan `edit_users` (se concede **solo** dentro de las pantallas de Flamingo).

### NovaFrames (NFS)
- Concede las _caps_ necesarias **solo** al visitar `nfs-generated` o `nfs-leads`.
- Si el plugin cuelga menús como **submenús** y el padre está oculto, se crea un **padre propio**
  (sin contenido) y se cuelgan los **slugs reales**.

### Elementor Submissions (solo lectura)
- Envoltorio de **REST** para permitir `GET` al operador en rutas que coincidan con:
  ```regex
  ^/(elementor|elementor-pro)/v\d+/.+(form|submission)
  ```
  Ej.: `/elementor/v1/form-submissions`, `/elementor-pro/v2/forms`.
- Acciones de escritura (POST/PUT/DELETE) requieren `edit_pages` (que se concede como ancla **solo**
  cuando se abre `e-form-submissions`).

### Whitelist de pantallas (anclas suaves)
El rol recibe `edit_pages`/`manage_options` **solo** si la ruta `admin.php?page=` está en:
```
flamingo, flamingo_inbound, flamingo_outbound, flamingo_contact,
e-form-submissions, nfs-generated, nfs-leads
```

> Si deseas el **máximo hardening**, elimina cualquier `add_cap('edit_pages')` global y deja
solo el otorgamiento **condicional** (`user_has_cap` con la whitelist).

---

## ⚙️ Switches y constantes
- `OPE_LOG_ENABLED` (`false` por defecto): activa el **logger**.
- `OPE_LOG_MAX_BYTES` (1MB): tamaño para rotación del log.
- `OPE_FLAMINGO_GRANT_EDIT_USERS` (`false`): concede `edit_users` solo dentro de Flamingo si fuera necesario.
- `OPE_TARGET_ROLES`: array de roles objetivo (por defecto `['operador_formularios','operador_base']`).

---

## 🗂️ Logger interno
- Archivo: `/wp-content/uploads/ope-logs/rol-operador.log`
- Visor: **Herramientas → Logs Operador** (se oculta si `OPE_LOG_ENABLED` está en `false`).
- Cada línea es JSON con `ts`, `uid`, `roles`, `uri`, `msg` y `ctx`.

Para apagarlo: 
```php
if (!defined('OPE_LOG_ENABLED')) define('OPE_LOG_ENABLED', false);
```

---

## ➕ Añadir otra pantalla puntual
1. Agrega el slug al array **whitelist** del bloque `user_has_cap`:
```php
$whitelist[] = 'tu-nuevo-slug';
```
2. (Opcional) Añade el menú (padre propio o submenú al existente):
```php
add_action('admin_menu', function () {
  if (!ope_current_user_has_any_role()) return;
  add_submenu_page('nfs-operator','Mi pantalla','Mi pantalla','read','tu-nuevo-slug','__return_null');
}, 10000);
```

Si esa pantalla usa REST, añade su matcher a la función de envoltorio (similar al de Elementor).

---

## 🧯 Rollback de emergencia
1. Renombra la carpeta del plugin: `rol-operador-base` → `rol-operador-base.off`.
2. Reinicia PHP‑FPM / resetea OPcache en Plesk.
3. Corrige el archivo y vuelve a su nombre original.

---

## 🔒 Notas de seguridad
- No se otorgan capacidades administrativas de forma global al rol; se aplican **por pantalla**.
- Los menús “puente” nunca cargan contenido propio: redirigen/abren **los slugs reales** del plugin objetivo.
- El envoltorio REST solo autoriza **GET** por defecto.

---

## 🧾 Changelog
- **1.0.0** — Primera versión estable (rol + menús + Flamingo + NFS + Submissions (GET) + logger).
