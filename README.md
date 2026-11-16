# Liveo

Aplicación Android (Flutter) + API PHP para XAMPP. Proveedor de eventos y señales: Matchora.

```
Liveo/
├── app/        Flutter (se compila en GitHub Actions)
├── backend/    Todo su contenido va dentro de C:\xampp\htdocs\Liveo
└── .github/    Workflow que genera la APK
```

## 1. Backend en XAMPP (localhost)

1. Copia **el contenido** de la carpeta `backend` dentro de `C:\xampp\htdocs\Liveo\`
   (debe quedar `C:\xampp\htdocs\Liveo\index.php`, `api\`, `src\`, `database\`…).
2. En el panel de XAMPP inicia **Apache** y **MySQL**.
3. Abre `http://localhost/Liveo/` → verás el panel de diagnóstico.
4. Pulsa **Instalar base de datos** (o importa `database/liveo.sql` en phpMyAdmin).
5. Pulsa **Probar proveedor** para confirmar que tu PC alcanza Matchora.

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
2. Actions → **Liveo Android APK** → *Run workflow*. También corre solo al hacer push a `main`/`master`.
3. Descarga el artefacto `Liveo-Android-APK` (`app-release.apk`) e instálalo en el teléfono.

El workflow:
- genera la plataforma Android (`flutter create`), añade el permiso `INTERNET` y habilita HTTP en red local
  (sin esto la APK *release* no podría conectar con `http://192.168.54.109`),
- compila con `API_BASE_URL=http://192.168.54.109/Liveo/api`.

Para usar otra URL sin tocar el código: *Settings → Secrets and variables → Actions → Variables → New variable*
`API_BASE_URL`.

Compilar en local: `cd app && flutter create --platforms=android --project-name liveo . && flutter run --dart-define=API_BASE_URL=http://192.168.54.109/Liveo/api`
(recuerda añadir INTERNET y `usesCleartextTraffic` al manifiesto, como hace el workflow).

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
No crea eventos: los eventos los genera Matchora y el panel solo les **agrega enlaces m3u8**.

1. Abre `http://192.168.54.109/Liveo/admin/` (solo desde la red local). La primera vez crea la contraseña
   (mínimo 8 caracteres, se guarda cifrada). Las tablas nuevas se crean solas; no hace falta reinstalar.
2. **Eventos**: lista los eventos de Matchora por fecha (o solo los que están en vivo) con buscador.
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
  usa el reproductor de Matchora (iframe) también en horizontal; y si tiene ambos, puedes cambiar desde el menú de fuentes.
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
- El evento ahora se reproduce **dentro de la app**: el `embed_url` de Matchora se carga en un `iframe`
  (como indica https://matchora.to/developers) con selección automática de dominio de respaldo
  (matchora.to / matchora.pro / es.matchora.pro). Ya no se abre la página completa con el aviso
  "Watch free at matchora.to". La publicidad del reproductor no se oculta ni se tapa (condición de uso de Matchora).
- Inicio rediseñado: fondo animado, carrusel de destacados, filtro por liga, tarjetas con equipos,
  marcador y minuto, animaciones de entrada, barra inferior flotante y pantalla completa en el reproductor.

## Alcance de Matchora y Chromecast
Matchora entrega `embed_url` para su reproductor embebido y no URLs directas de los streams, por lo que el Cast
nativo solo podrá activarse para fuentes con media URL compatible. El botón actual informa esta limitación.
