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
