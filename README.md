# Liveo

Aplicación Android (Flutter) + API PHP para XAMPP. Los eventos se cargan a mano o con un **CSV** desde el panel de administración; las señales son enlaces m3u8 que agregas a cada evento.

```
Liveo/
├── app/        Flutter (se compila en GitHub Actions)
├── backend/    Todo su contenido va dentro de C:\xampp\htdocs\Liveo
└── .github/    Workflow que genera la APK
```

## Cambios 1.0.32

- **Chromecast corregido (app + backend):**
  - Las señales ahora se transmiten a través de Liveo (`backend/api/cast.php`): el televisor le pide el video a tu PC y la PC se lo pide al servidor original con el Referer / User-Agent guardados en el panel y con CORS. Así se puede enviar cualquier fuente m3u8, no solo las que el Chromecast abría solo.
  - Espera a que el televisor esté conectado antes de enviar la señal (antes podía fallar o quedar en negro) y reintenta una vez.
  - Si el evento abierto es una página web (iframe), transmite su primera señal directa; si no tiene ninguna, avisa.
  - Mientras transmites, elegir otro evento de la lista lo manda al televisor (antes cortaba la transmisión). Al detenerla, el celular retoma el evento que quedó elegido.
  - Archivos: `app/lib/cast_service.dart`, `app/lib/live_player.dart`, `app/lib/main.dart`, `app/pubspec.yaml` (versión 1.0.32+31) y `backend/api/cast.php` (nuevo).

- **Cruces «A o B»** (por ejemplo «Orlando City o FC Cincinnati»): ya no se tratan como un equipo más.
  - Al importar un CSV con el cruce definido (Nashville vs FC Cincinnati), el partido guardado con «A o B» se completa solo y conserva sus señales, en lugar de duplicarse.
  - Si un CSV vuelve a traer el «A o B» de un cruce ya definido, no se vuelve a crear.
  - En Eventos propios aparece la tarjeta «Cruces por definir» con un botón por equipo (Pasa X) y lo mismo en la ficha del evento.
- Archivo `liveo_1.0.32_mls.sql`: define los dos cruces de MLS y agrega las revanchas (Cincinnati vs Nashville y Real Salt Lake vs Vancouver).

## Cambios 1.0.31
- **Panel → Deportes:** cada deporte puede tener un logo propio (subido desde tu equipo o por URL) que la app muestra en lugar del ícono;
  también puedes editar su nombre (con opción de renombrarlo en los eventos existentes) y quitarlo, borrando el archivo subido.

## Cambios 1.0.30
- **Filtro de deportes nuevo:** sale de la fila de chips y pasa a un botón con ícono de filtro, a la izquierda de «Todos» (se enciende cuando
  hay un deporte elegido). Abre una hoja moderna con un ícono por deporte y el número de eventos; para quitar el filtro se toca de nuevo
  el deporte elegido.
- **Panel → menú nuevo «Deportes»:** agrega deportes y cambia el ícono de cada uno (16 íconos disponibles). El nombre debe coincidir con el
  campo «Deporte» de los eventos (sin importar mayúsculas ni tildes); un deporte que no esté en la lista usa el ícono de trofeo. Trae una lista
  de fábrica hasta que guardes la tuya. La app la pide al servidor (`/api/sports`) y, si no responde, usa la de fábrica.
- **Cabecera más compacta:** menos espacio entre el anuncio, el título y los botones; sin anuncios, los chips suben solos. Logo un poco más
  grande y la misma tipografía (Outfit ExtraBold) para «LIVEO» en el inicio y en la presentación.
- **Imagen en imagen:** se quitó de Ajustes; ahora está siempre activa al salir del reproductor con un directo abierto.
- Dependencias nuevas: `flutter_svg`. Recursos nuevos: `app/assets/sports/` (íconos Tabler, MIT) y `app/assets/fonts/` (Outfit, licencia OFL).
- Archivos modificados: `app/lib/main.dart`, `app/lib/live_player.dart`, `app/lib/sport_icons.dart` (nuevo), `app/pubspec.yaml`, `app/assets/**` (nuevo),
  `backend/admin/index.php`, `backend/api/index.php`, `backend/src/bootstrap.php`, `backend/src/sports_catalog.php` (nuevo), `README.md`.
  No hay que ejecutar ningún SQL. La carpeta `.github` no cambia.

## Cambios 1.0.29
- **Chips del inicio** (Todos, En vivo, Hoy, Próximos, Deporte): ahora rectangulares y un poco más pequeños.
- **Título «LIVEO»** de la cabecera con letra más pequeña.
- **Modo claro en el reproductor:** la página bajo el video (información, opciones y más partidos) respeta el tema; antes seguía negra.
- **Barra inferior:** se oculta al bajar con scroll y reaparece al subir.
- **Panel, enlaces del evento:** botones ▲ Subir / ▼ Bajar para decidir el orden de los enlaces (reemplazan el campo numérico «Orden»).
- Archivos modificados: `app/lib/main.dart`, `app/lib/live_player.dart`, `app/pubspec.yaml`, `backend/admin/index.php`,
  `backend/src/bootstrap.php`, `README.md`. La carpeta `.github` no cambia.

## Cambios 1.0.28
- **Equipos y competiciones sin repetir:** cada equipo y cada competición tiene ahora un único nombre. Al importar un CSV (y al guardar
  un evento a mano) Liveo pasa los nombres al que ya existe: une las variantes con otras tildes o mayúsculas, las que solo cambian
  «1.», «FC», «VfB», «VfL»… (por ejemplo «1. FC Köln» = «Köln») y los alias de fábrica («Atlético-MG» = «Atlético Mineiro»,
  «Athletico-PR» = «Athletico Paranaense», «Brasileirão Série A» = «Brasileirão»). Se prefiere siempre el nombre que ya tiene escudo / logo.
- **Menú nuevo «Nombres»** en el panel: ahí agregas tus propios alias, una línea por alias (`Alias = Nombre oficial`; para competiciones
  `liga: Alias = Nombre oficial`). Al guardar se corrigen también los eventos ya cargados.
- **Importar dos CSV distintos ya no duplica partidos:** la clave de importación pasa a ser fecha + local + visitante (antes incluía el
  nombre de la competición y el texto del evento). Si el partido ya existe (mismos equipos, ±24 h) se actualiza en lugar de crear otro,
  aunque lo hubieras creado a mano.
- **Limpieza automática, una sola vez:** al abrir el panel o la API después de subir esta versión, los nombres repetidos que ya estaban
  en la base se unifican, se recalculan las claves y, si hubiera un mismo partido cargado dos veces, se funde en uno (conserva sus señales
  y favoritos). No hay que ejecutar ningún SQL.
- Archivos modificados: `backend/src/manual.php`, `backend/src/importer.php`, `backend/admin/index.php`, `backend/src/bootstrap.php`,
  `app/lib/main.dart`, `app/pubspec.yaml`, `README.md`. La carpeta `.github` no cambia.

## Cambios 1.0.27
- **Logo de la competición en el inicio:** cada tarjeta de evento muestra el logo de la liga/competición a la izquierda de su
  nombre (por ejemplo, el logo de Liga Profesional junto al texto «Liga Profesional»). Usa los logos que ya cargaste en
  `admin/ → Competiciones`; si una competición no tiene logo no se muestra nada, y si la imagen no carga aparece un trofeo.
- **Nueva ventana de señales:** al tocar un evento con varias señales se abre una ventana centrada (ya no la hoja de abajo),
  con borde degradado, ícono de señal, título del partido, una fila por señal (número, nombre, etiqueta WEB / DIRECTO) y botón
  «Cancelar». Se adapta al tema claro y oscuro. Con una sola señal el reproductor sigue abriéndose directo.
- Archivos modificados: `app/lib/main.dart`, `app/pubspec.yaml`, `backend/src/bootstrap.php`, `README.md`.
  La carpeta `.github` no cambia y el backend solo cambia el número de versión.

## Cambios 1.0.26
- **Anuncios informativos en el inicio:** barra con megáfono y texto deslizante (como la de SportzX) arriba de los chips.
  Se cargan en `admin/ → Anuncios` (un anuncio por línea, hasta 10; pasan uno tras otro y se repiten) y se pueden
  ocultar con la casilla «Mostrar la barra». La app los pide al abrir y al deslizar para recargar; si no hay anuncios
  (o el servidor es una versión anterior) la barra no aparece.
- API nueva: `GET /api/announcements` → `{success, enabled, items[]}`. Se guarda en `app_settings` (no requiere tablas nuevas).
- **Opciones de enlaces más compactas:** cada fuente es una ficha de una sola línea (ícono + nombre + tilde si es la activa),
  sin el texto «Reproduciendo» que se cortaba. Directo / Web se distingue por el ícono. Hasta 4 fuentes en 2 columnas, más de 4 en 3.
- Archivos modificados: `backend/src/manual.php`, `backend/api/index.php`, `backend/admin/index.php`, `backend/src/bootstrap.php`,
  `app/lib/main.dart`, `app/lib/models.dart`, `app/lib/live_player.dart`, `app/pubspec.yaml`, `README.md`. La carpeta `.github` no cambia.

## Cambios 1.0.25
- **Pantalla del reproductor rediseñada:**
  - **Logo de la competición** a la izquierda del título del partido. Se carga una sola vez en
    `admin/ → Competiciones` (URL o subiendo la imagen) y aparece en todos los partidos de esa competición.
  - **Caja de opciones** más presentable: una ficha por fuente (Directo / Web), la activa resaltada como «Reproduciendo».
    Solo aparece cuando el evento tiene más de una fuente.
  - **Más partidos** debajo del video (misma competición; si no hay, del mismo deporte): en vivo primero y después
    por hora. Tocar uno cambia el reproductor a ese partido sin salir de la pantalla. Los que no tienen señal se ven atenuados.
- Backend: tabla nueva `league_logos` (se crea sola al abrir el panel o la API) y campo `league_logo` en los eventos.
- Archivos modificados: `backend/src/manual.php`, `backend/admin/index.php`, `backend/src/bootstrap.php`,
  `app/lib/live_player.dart`, `app/lib/models.dart`, `app/lib/main.dart`, `app/pubspec.yaml`, `app/test/widget_test.dart`.

## Cambios 1.0.24
- **Básquet / NBA con duración propia:** los partidos de básquet (deporte o competición con «básquet», «baloncesto»,
  «basketball» o «NBA») ya no se marcan como finalizados a los 135 min como el fútbol: por defecto duran **180 min**
  desde el inicio (cubre pausas, tiempos muertos y tiempo extra). Se cambia en `admin/ → Configuración →
  «Duración de un partido de básquet»`. Orden de prioridad: duración propia del evento > deporte (básquet) > general.
- El preview «Finalización automática» del formulario del evento también tiene en cuenta el deporte.
- **Panel → Eventos:** los partidos **en vivo** aparecen primero en la lista (después, el orden de siempre).
- **App → tarjeta en vivo:** las ondas de señal animadas van **arriba** y «EN VIVO» **abajo** (texto un poco más chico:
  11.5 → 10.5; ondas 15 → 18).
- Archivos modificados: `backend/src/manual.php`, `backend/admin/index.php`, `app/lib/main.dart` y `app/pubspec.yaml`.

## Cambios 1.0.23 (solo app; el backend sigue en 1.0.22)
- **Reproductor:** la insignia queda siempre como **«EN VIVO»** (ya no cambia a «IR AL DIRECTO»); si el video quedó
  atrasado, tocarla vuelve al directo. Se quita el botón «Pantalla completa (horizontal)» del panel inferior
  (el ícono de pantalla completa de la barra del video sigue disponible).

## Cambios 1.0.22
- **Enlaces iframe corregidos:** al pegar el código `<iframe …>` en un enlace del panel, ahora se **guarda el iframe**
  (limpio: `src`, `allow`, `allowfullscreen`, `referrerpolicy`, `sandbox`…) además de su URL, y la app lo **carga tal cual**
  dentro del reproductor (como en la web original), en vez de abrir solo la URL suelta. Se aceptan comillas dobles,
  simples o sin comillas, varias líneas y `src="//…"`. Al editar el enlace, el campo muestra el código iframe guardado.
- Nueva columna `embed` en `event_sources` (la base se migra sola al abrir el panel una vez).

## Cambios 1.0.21
- **Enlaces iframe además de m3u8:** en `admin/ →` evento puedes pegar una URL m3u8, la URL de una página web o el
  **código `<iframe …>` completo** (se toma su `src`). El «Tipo» es automático (`.m3u8` = reproductor de Liveo;
  cualquier otra URL = iframe) o se elige a mano. También funciona en «Agregar varios enlaces a la vez».
- En la app, los iframe se abren **dentro del reproductor** (WebView), también en vertical. Se bloquean las
  redirecciones a otros sitios (publicidad) y no se pueden transmitir por Chromecast. Vuelve la dependencia
  `webview_flutter`.
- Al actualizar, la base se migra sola (columna `kind` en `event_sources`).

## Cambios 1.0.20
- **Filtros del inicio:** `Todos · En vivo · Hoy · Próximos` (Próximos = desde pasado mañana, p. ej. 20/11) y un
  nuevo **filtro de deportes** (chip «Deporte»), con los deportes que haya en el calendario.
- **Tarjeta:** debajo de la hora va la fecha (`20/11`), nunca «Mañana»; barra separadora entre la hora y los
  escudos; señal «EN VIVO» y cuenta regresiva un poco más chicas.
- **Cuenta regresiva:** con días de por medio muestra «Comienza en 2 días»; recién el mismo día pasa a la cuenta
  en horas · minutos · segundos (nunca los 4 casilleros).
- **Escudos que se guardan para siempre:** súbelos o pégalos una vez (en `admin/ → Escudos`, o en un evento, o en el
  CSV) y quedan para todos los partidos de ese equipo. Se pueden **subir imágenes** (por equipo o muchas a la vez,
  con el nombre del equipo como nombre de archivo: `Chelsea.png`). Se guardan en `backend/uploads/logos/`.
- **Varios enlaces por evento:** nuevo cuadro «Agregar varios enlaces a la vez» (una línea por enlace:
  `Nombre | URL`). En la app, al tocar un evento con 2 o más enlaces se abre una **ventana con las opciones** una
  debajo de la otra; con un solo enlace abre directo el reproductor.
- **Reproductor en vertical:** se abre en vertical (video arriba; título y opciones debajo). Pasar a horizontal
  es opcional con el botón de pantalla completa.

## Cambios 1.0.19
- Tarjeta de evento **más compacta**: sin ícono de deporte, escudos y nombres con más espacio, cuenta regresiva
  más chica y **«EN VIVO» con la señal (ondas) al lado, en la misma línea**.
- La cuenta regresiva **oculta los días cuando ya falta menos de 24 h** (queda HRS · MIN · SEG).

## Cambios 1.0.18
- **RockyLive eliminado por completo** (API, panel, app y documentación): ya no hay publicidades de terceros ni
  reproductor web. Los eventos son propios: se cargan a mano o **subiendo un CSV** en `admin/ → Importar CSV`.
- **Importar CSV**: acepta el formato del calendario (`fecha, hora_Argentina_ART, deporte, competicion, evento…`);
  «A vs B» se separa en local y visitante; sin hora queda como **hora a confirmar**. Volver a subir el CSV
  actualiza los eventos sin duplicarlos y conserva estado, minuto, visibilidad y enlaces. Hay plantilla descargable.
  Columnas opcionales: `hasta` (hora de fin HH:MM, o `duracion_min`; define hasta cuándo está «en vivo» cada evento),
  `local`, `visitante`, `escudo_local`, `escudo_visitante`, `estado`, `minuto`.
- **Minuto asociado a cada evento**: en el evento (panel) → *Control en vivo* (estado + minuto). La app lo muestra
  en rojo debajo de la hora, solo mientras está en vivo.
- **Escudos**: por evento (URL) o una sola vez por equipo en `admin/ → Escudos` (también por CSV `equipo,escudo`).
  Sin escudo se muestran las iniciales del equipo.
- **Nueva tarjeta**: competición arriba + barra separadora; hora, ícono del deporte, escudos y rivales uno
  debajo del otro; y del otro lado de la barra la **cuenta regresiva (días · horas · minutos · segundos)**.
  Al empezar pasa a **EN VIVO** con la animación de ondas. **Toda la tarjeta abre el reproductor.**
- Nuevo endpoint `GET /api/upcoming?days=14` (el inicio carga hoy + 14 días). Se elimina `/api/channels`.
- Se quita el ajuste «Reproductor» (ya no hay reproductor de tercero) y la dependencia `webview_flutter`.
- Al actualizar, abre el panel una vez: la base se migra sola (columnas `minute`, `home_logo`, `away_logo`,
  `time_tbc`, `import_key` y tabla `team_logos`). Los enlaces de eventos `rl-…` se borran.

## 1. Backend en XAMPP (localhost)

1. Copia **el contenido** de la carpeta `backend` dentro de `C:\xampp\htdocs\Liveo\`
   (debe quedar `C:\xampp\htdocs\Liveo\index.php`, `api\`, `src\`, `database\`…).
2. En el panel de XAMPP inicia **Apache** y **MySQL**.
3. Abre `http://localhost/Liveo/` → verás el panel de diagnóstico.
4. Pulsa **Instalar base de datos** (o importa `database/liveo.sql` en phpMyAdmin).
5. Entra a `http://localhost/Liveo/admin/` y sube tu CSV en **Importar CSV**.

No hace falta crear `config.local.php` con XAMPP por defecto (root sin contraseña). Si tu MySQL tiene
contraseña, copia `src/config.local.example.php` a `src/config.local.php` y edítalo.

URLs finales (IP de tu PC: `192.168.54.109`):

| Qué | URL |
| --- | --- |
| Panel | `http://192.168.54.109/Liveo/` |
| Salud | `http://192.168.54.109/Liveo/api/health` |
| Agenda | `http://192.168.54.109/Liveo/api/schedule` |
| En vivo | `http://192.168.54.109/Liveo/api/live` |
| Próximos | `http://192.168.54.109/Liveo/api/upcoming?days=14` |
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
- **"SSL certificate problem"** al probar un enlace m3u8 desde el panel: descarga `https://curl.se/ca/cacert.pem`, guárdalo en
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
