# Liveo

Aplicación Android (Flutter) + API PHP para XAMPP. Proveedor de eventos y señales: RockyLive (https://rockylive.st/api/).

```
Liveo/
├── app/        Flutter (se compila en GitHub Actions)
├── backend/    Todo su contenido va dentro de C:\xampp\htdocs\Liveo
└── .github/    Workflow que genera la APK
```

## Cambios 1.0.16
- **Matchora eliminado por completo** (API, panel, app y documentación). El proveedor ahora es la
  **RockyLive API** (`https://rockylive.st/api/events.php?limit=1000`, sin clave).
- Quedan excluidos: canales 24/7 (incluidos NFL Network y NFL RedZone), darts, WWE, College Basketball,
  NBL, Allsvenskan y NHL (además del resto de competiciones que ya estaban excluidas).
- Los ids de RockyLive se guardan como `rl-<id>`; los enlaces m3u8 y favoritos asociados a eventos de
  Matchora ya no aplican a los eventos nuevos. Se limpia la caché del proveedor anterior.
- La API ya no tiene `/api/channels` (RockyLive solo ofrece canales 24/7, que están excluidos).
- Como RockyLive no informa estado ni marcador, "en vivo" se calcula por la hora de inicio y una
  duración según el deporte; después el evento figura como Finalizado.
- Eventos sin equipos (p. ej. sesiones de Fórmula 1) se muestran solo con su título.

## 1. Backend en XAMPP (localhost)

1. Copia **el contenido** de la carpeta `backend` dentro de `C:\xampp\htdocs\Liveo\`
   (debe quedar `C:\xampp\htdocs\Liveo\index.php`, `api\`, `src\`, `database\`…).
2. En el panel de XAMPP inicia **Apache** y **MySQL**.
3. Abre `http://localhost/Liveo/` → verás el panel de diagnóstico.
4. Pulsa **Instalar base de datos** (o importa `database/liveo.sql` en phpMyAdmin).
5. Pulsa **Probar proveedor** para confirmar que tu PC alcanza RockyLive.

No hace falta crear `config.local.php` con XAMPP por defecto (root sin contraseña). Si tu MySQL tiene
contraseña, copia `src/config.local.example.php` a `src/config.local.php` y edítalo.

URLs finales (IP de tu PC: `192.168.54.109`):

| Qué | URL |
| --- | --- |
| Panel | `http://192.168.54.109/Liveo/` |
| Salud | `http://192.168.54.109/Liveo/api/health` |
| Agenda | `http://192.168.54.109/Liveo/api/schedule` |
| En vivo | `http://192.168.54.109/Liveo/api/live` |
| Canales | `http://192.168.54.109/Liveo/api/channels` |
| Evento | `http://192.168.54.109/Liveo/api/events/{id}` |
| Favoritos | `http://192.168.54.109/Liveo/api/favorites` (GET/POST/DELETE) |
| **Administración** | `http://192.168.54.109/Liveo/admin/` |

### Para que el teléfono llegue a tu PC
- Teléfono y PC en la misma red Wi-Fi.
- Abre el puerto 80 en el firewall de Windows (CMD como administrador):
  `netsh advfirewall firewall add rule name="Apache XAMPP 80" dir=in action=allow protocol=TCP localport=80`
- Prueba primero `http://192.168.54.109/Liveo/api/health` en el navegador del teléfono.
- Si el router cambia la IP de la PC, reserva esa IP en el router (DHCP) o vuelve a compilar la APK.

### Problemas típicos
- **"SSL certificate problem"** en *Probar proveedor*: descarga `https://curl.se/ca/cacert.pem`, guárdalo en
  `C:\xampp\php\extras\ssl\cacert.pem` y pon `'ca_bundle' => 'C:/xampp/php/extras/ssl/cacert.pem'` en `src/config.local.php`.
- **404 en `/Liveo/api/health`**: verifica que `mod_rewrite` esté activo y `AllowOverride All` en `httpd.conf` (XAMPP lo trae así).

## 2. APK con GitHub Actions

1. Descomprime el zip y sube **el contenido** a la raíz de un repositorio (la carpeta `.github` debe quedar en la raíz).
2. Actions → **Liveo Android APK** → *Run workflow*. Solo corre a mano (ya no en cada push).
3. Descarga el APK desde **Releases** (`Liveo-<versión>.apk`) o, si existe, del artefacto `Liveo-Android-APK`, e instálalo en el teléfono.

El workflow:
- genera la plataforma Android (`flutter create`), añade el permiso `INTERNET` y habilita HTTP en red local
  (sin esto la APK *release* no podría conectar con `http://192.168.54.109`),
- compila con `API_BASE_URL=http://192.168.54.109/Liveo/api`.

Para usar otra URL sin tocar el código: *Settings → Secrets and variables → Actions → Variables → New variable*
`API_BASE_URL`.

Compilar en local: `cd app && flutter create --platforms=android --project-name liveo . && flutter run --dart-define=API_BASE_URL=http://192.168.54.109/Liveo/api`
(recuerda añadir INTERNET y `usesCleartextTraffic` al manifiesto, como hace el workflow).

## Cambios 1.0.11 (backend)
- Rediseño visual del backend y del panel de administración: interfaz oscura refinada, mejor jerarquía visual,
  tarjetas, navegación, formularios y adaptación a móviles.
- El panel ahora combina **eventos en vivo + agenda de la fecha seleccionada + agenda del día siguiente**,
  igual que la app, y elimina duplicados por ID. Así aparecen también los eventos que antes solo se veían en la app.
- Cricket queda excluido de la agenda, eventos en vivo y canales de la API. También se limpian los enlaces
  guardados cuya liga está identificada como Cricket.
- El favicon y el icono de acceso del backend, administración e instalación usan el mismo logo de Liveo que la APK.
- No requiere modificar ni recompilar la aplicación Android; reemplaza el backend en XAMPP.

## Cambios 1.0.10
- Workflow: el APK ahora se publica en una **Release** del repositorio (`v<versión>`, archivo `Liveo-<versión>.apk`),
  que no cuenta en el cupo de artefactos de Actions. Descárgalo en la pestaña **Releases** (columna derecha del repo).
- La subida como artefacto queda como copia opcional: si el cupo está lleno, ya no hace fallar la compilación.
- Requiere que Actions pueda escribir: *Settings → Actions → General → Workflow permissions → Read and write permissions*.

## Cambios 1.0.9
- Workflow: corre **solo a mano** (*Run workflow*) y el artefacto se conserva **2 días** (antes 14), para no
  agotar el almacenamiento de artefactos de GitHub (`Artifact storage quota has been hit`).
- Si el error persiste: borra artefactos y ejecuciones viejas en Actions; el uso se recalcula cada 6-12 horas.

## Cambios 1.0.8
- Corrige el error de `flutter analyze` de la 1.0.7: `Undefined class 'ValueListenable'` (se importa desde
  `package:flutter/foundation.dart`). Es la misma 1.0.7 de diseño, ya compilable.

## Cambios 1.0.7 (diseño)
Primera entrega del rediseño de inicio (estilo PlayZ TV, sin escudos, marcadores, canales ni categorías).
- **Inicio**: cabecera con ★ favoritos, ⟳ actualizar y 🔍 buscar; fila de **chips con contador**
  (Todos · En vivo · Hoy · Mañana) y tarjetas de evento con liga, equipos, hora, día y cuenta atrás
  («Empieza en 59:33») o «EN VIVO» con el minuto. La pantalla «En vivo» pasa a ser un chip.
- **Barra inferior flotante** en píldora: Eventos · Agenda · Ajustes.
- **Buscador**: pantalla propia con campo redondeado, sugerencias (en vivo ahora y ligas), historial reciente,
  búsqueda sin acentos ni mayúsculas, coincidencias resaltadas y filtro «Solo en vivo». Busca en los eventos
  de hoy y mañana.
- **Favoritos** (★): guardados en el teléfono por evento; también se marcan con la estrella de cada tarjeta.
- **Agenda**: selector de día en formato compacto y las mismas tarjetas.
- **Ajustes** en tarjetas: modo oscuro, **imagen en imagen al salir** (ahora se puede desactivar), servidor,
  reproductor, borrar datos de la app (favoritos e historial), versión y salir.
- Los favoritos y el historial de búsqueda son locales; el endpoint `/api/channels` sigue en el backend
  pero la app ya no lo usa.
- Pendiente para la 1.0.11: `/api/config` (banner de actualización, URL de la API remota), reporte de enlaces
  caídos y caché de la agenda.

## Cambios 1.0.6
- Corrige el error de Gradle `permission_handler_android requires … compile against version 37`: el workflow
  sube `compileSdk` a 37 en `android/app/build.gradle(.kts)` (compatible hacia atrás).

## Cambios 1.0.5
- Corrige los errores de `flutter analyze` de Chromecast 2.0.0: sus constantes son en minúscula
  (`GoogleCastConnectState.connected`, `CastMediaStreamType.live`).

## Cambios 1.0.4
- **Corrige el error de compilación de Chromecast** (`Namespace not specified … flutter_chrome_cast`): el paquete
  pasa de la 0.0.11 (antigua, sin `namespace`) a la **2.0.0**, compatible con el Gradle actual.
- El workflow suma un paso de seguridad que agrega el `namespace` a cualquier plugin antiguo que no lo traiga.
- El único archivo de workflow es `.github/workflows/android-apk.yml` (se eliminó la copia `github-workflow/`).

## Cambios 1.0.3

### Panel de administración (enlaces m3u8)
No crea eventos: los eventos vienen de RockyLive y el panel solo les **agrega enlaces m3u8**.

1. Abre `http://192.168.54.109/Liveo/admin/` (solo desde la red local). La primera vez crea la contraseña
   (mínimo 8 caracteres, se guarda cifrada). Las tablas nuevas se crean solas; no hace falta reinstalar.
2. **Eventos**: lista los eventos de RockyLive por fecha (o solo los que están en vivo) con buscador.
   Cada fila muestra cuántos enlaces tiene. Toca un evento para gestionarlos.
3. En el evento: agrega enlaces (hasta 10) con nombre, URL m3u8 y, si el servidor del stream lo exige,
   `Referer` y `User-Agent`. Puedes **Probar** cada enlace (el servidor comprueba que sea una lista HLS válida
   y si es en vivo), editarlo, ordenarlo, desactivarlo o eliminarlo.
4. **Con enlaces**: todos los eventos que ya tienen enlaces, y un botón para limpiar los de hace más de 3 días.

La API (`schedule`, `live`, `events/{id}`) agrega a cada evento el campo `links` con sus enlaces activos.
¿Olvidaste la contraseña? En phpMyAdmin: `DELETE FROM app_settings WHERE setting_key='admin_password_hash';`

### App
- **Inicio en listas**: dos secciones (En vivo y Próximos con señal), eventos agrupados por liga, una fila
  compacta por evento (hora o minuto, equipos, marcador y botón de play). Sin círculos de iniciales ni carrusel.
- Colores y efectos reducidos a lo mínimo: neutros, verde de acento y rojo solo para "en vivo".
- **Barra inferior** estándar de Material (como la original). **Agenda** con el mismo formato que En vivo
  (todos los eventos se pueden abrir) y navegación por días.
- **Reproductor propio** (`lib/live_player.dart`): HLS en vivo con ExoPlayer, en horizontal, controles
  minimalistas que se ocultan, selector de fuentes, reintento automático, botón "Ir al directo",
  **imagen en imagen** (también al pulsar inicio) y **Chromecast**. Si un evento no tiene enlaces propios,
  usa el reproductor de RockyLive (iframe) también en horizontal; y si tiene ambos, puedes cambiar desde el menú de fuentes.
- Android nativo: `app/native/MainActivity.kt.tpl` (PiP y pantalla encendida) y permisos de Cast se aplican
  solos en el workflow.
- Chromecast usa el paquete `flutter_chrome_cast` 2.0.0 (versión fija). El televisor descarga el stream por sí
  mismo, así que las fuentes con Referer/User-Agent pueden no funcionar al transmitir.

## Cambios 1.0.2
- **Logo e icono propios**: anillo de señal en degradado, play y punto rojo "en vivo". Icono adaptable de Android
  (con versión monocromática para iconos temáticos) incluido en `app/branding/android/res`; el workflow lo copia
  a la APK automáticamente. Para regenerarlo: `python3 app/branding/generate_icons.py` (pillow y numpy).
  `app/branding/liveo_icon_1024.png` es el icono en alta resolución.
- **Presentación al abrir**: el anillo se dibuja, aparece el play y el punto en vivo con ondas de señal, entra
  el nombre y un cargador de barras de ecualizador (sin texto) antes del fundido hacia el inicio.
- El logo también aparece en la cabecera del inicio y en Ajustes. Pantalla de arranque nativa oscura.

## Cambios 1.0.1
- El evento se reproduce **dentro de la app**: el `embed_url` de RockyLive se carga en un `iframe`
  (como indica https://rockylive.st/api/). La publicidad del reproductor no se oculta ni se tapa.
- Inicio rediseñado: fondo animado, carrusel de destacados, filtro por liga, tarjetas con equipos,
  marcador y minuto, animaciones de entrada, barra inferior flotante y pantalla completa en el reproductor.

## Alcance de RockyLive y Chromecast
RockyLive entrega `embed_url` para su reproductor embebido y no URLs directas de los streams, por lo que el Cast
nativo solo podrá activarse para fuentes con media URL compatible. El botón actual informa esta limitación.


## Eventos propios (panel de administración)

En `http://IP/Liveo/admin/` → **Eventos propios** podés crear eventos a mano:
deporte, competición, equipos, título (opcional), inicio y estado
(Automático por horario, Programado, En vivo, Finalizado, Postergado, Cancelado).
La finalización automática es inicio + 135 minutos; se cambia en **Configuración**.
Cada evento admite señales (enlaces m3u8) igual que los de RockyLive y se mezcla con
ellos en `/api/live`, `/api/schedule` y `/api/events/manual-N`.

Tras actualizar el backend, abrí el panel una vez (o `setup.php`) para que se cree la
tabla `manual_events`.
