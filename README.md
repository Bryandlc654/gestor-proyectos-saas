# FlowTasks - Gestor de Proyectos, Tareas, Tickets y Recursos

Un sistema de gestión ágil, moderno e intuitivo desarrollado en **PHP 8**, **MySQL** y estilizado con **Tailwind CSS**, diseñado con un enfoque prioritario en la experiencia de usuario (**UI/UX**) y simplicidad de uso.

---

## 🚀 Características Principales

### 0. Multi-agencia (SaaS)
- **Cada agencia es un espacio aislado**: sus usuarios, proyectos, tareas, facturas y marca (nombre y logo) solo los ve esa agencia. El filtro se aplica en el servidor a partir de la agencia guardada en la base de datos, no de la sesión.
- **Registro = nueva agencia**: quien se registra crea su agencia y queda como administrador. Ya no se puede elegir rol al registrarse.
- **Invitaciones por enlace**: el administrador invita a su equipo o a sus clientes desde *Usuarios → Invitar*, eligiendo el rol. El enlace sirve una sola vez, caduca a los 7 días y se puede revocar; en la base de datos solo se guarda su hash.
- Los roles (administrador, project manager, colaborador, cliente) funcionan igual que antes, pero siempre dentro de la propia agencia.

### 1. Autenticación y Seguridad
- **Inicio de sesión y registro de agencias** con validación y contraseñas cifradas mediante `password_hash()` con algoritmo **BCrypt**.
- Protección **CSRF** en todos los formularios y acciones del sistema.
- Consultas parametrizadas con **PDO Prepared Statements** para prevenir inyección SQL.
- Sanitización de salidas con `htmlspecialchars` contra ataques XSS.
- Protección en la carpeta `uploads/` mediante directivas `.htaccess` para bloquear ejecución de scripts maliciosos.

### 2. Dashboard Inteligente
- **Métricas visuales a primera vista**: Proyectos totales, tareas pendientes y en progreso, tickets activos y porcentaje de avance.
- Listado de proyectos con barra de progreso dinámica y contadores de recursos.
- Panel de tareas urgentes con detección de vencimientos cercanos.

### 3. Vista de Proyecto Modular (Pestañas Integradas)
Dentro de cada proyecto podrás gestionar:
1. 📋 **Actividades / Tareas**:
   - **Doble Vista: Tablero Kanban y Vista Lista**: Conmuta entre columnas dinámicas (*Por Hacer, En Progreso, Completada*) o formato lista compacto.
   - **Asignación a Colaboradores**: Selector desplegable con la lista real de miembros del equipo.
   - **Detalle y Comentarios por Tarea**: Al hacer clic en cualquier tarea se abre su modal de detalle, donde puedes ver y publicar notas o pegar capturas (`Ctrl+V`) asociadas exclusivamente a esa tarea.
2. ✅ **Checklists**: Listas de verificación interactivas con cálculo de avance porcentual y actualización instantánea vía API sin recargar la página.
3. 🎫 **Tickets de Soporte / Incidentes**: Gestión de fallas técnicas o solicitudes con niveles de severidad (*Baja, Media, Alta, Crítica*) y estados (*Abierto, En Revisión, Resuelto, Cerrado*).
4. 💬 **Muro de Notas y Comentarios**: Feed colaborativo general y visualización de comentarios originados en tareas (con chip de acceso directo a la tarea), con soporte para adjuntar y **pegar capturas con `Ctrl + V`**.
5. 📎 **Adjuntos (Archivos y Enlaces con Carpetas)**:
   - **Gestor de Carpetas**: Creación de carpetas con color personalizado y filtro instantáneo en vivo.
   - **Archivos Descargables**: Subida y descarga segura de documentos clasificados por carpetas.
   - **Enlaces Web**: Directorio organizado de URLs externas (Figma, GitHub, Notion, Drive) por carpetas.

---

## 🛠️ Requisitos de Ejecución
- **PHP 8.1 o superior** con extensiones `pdo`, `pdo_mysql` y `fileinfo` habilitadas.
- **MySQL 5.7+** o **MariaDB 10.3+**.
- Servidor local como **XAMPP**, **Laragon**, **WampServer** o el servidor embebido de PHP.

---

## 📦 Puesta en Marcha

### Paso 1: Configurar Credenciales (.env)
Copia `.env.example` como `.env` y ajusta los valores de conexión. El archivo `.env` está en `.gitignore` y **nunca debe subirse al repositorio**:
```bash
cp .env.example .env
```
Usa `APP_ENV=development` en local para ver detalles de errores y `APP_ENV=production` en el servidor.

### Paso 2: Crear las Tablas (migraciones)
Crea una base de datos vacía (`utf8mb4`) y ejecuta las migraciones versionadas de `database/migrations/`:
```bash
php database/migrate.php
```
Comandos útiles:
```bash
php database/migrate.php --status
```
```bash
php database/migrate.php --seed
```
- `--status` muestra qué migraciones están aplicadas y cuáles pendientes (se registran en la tabla `schema_migrations`).
- `--seed` carga datos de demostración (solo con `APP_ENV=development`).
- Para un cambio de esquema nuevo, crea `database/migrations/NNNN_descripcion.sql` con el siguiente número y vuelve a ejecutar `php database/migrate.php`.
- El ejecutor solo funciona por línea de comandos; no es accesible desde el navegador.

### Paso 3: Iniciar la Aplicación
Puedes colocar esta carpeta dentro de `htdocs` (en XAMPP) o `www` (en Laragon), o bien ejecutar directamente el servidor integrado de PHP en esta carpeta:
```bash
php -S localhost:8000
```
Luego abre tu navegador en:
```
http://localhost:8000
```

---

## 🔑 Credenciales de Demostración
Si cargaste los datos demo con `--seed` (solo en local), hay dos agencias: *Agencia Demo*, con cuentas para los cuatro roles (`admin@demo.com`, `pm@demo.com`, `colaborador@demo.com`, `cliente@demo.com`), y *Estudio Norte* (`admin@norte.com`, `colaborador@norte.com`), útil para comprobar que una agencia no ve el contenido de otra. La contraseña está indicada al inicio de `database/seeds/demo.sql`. **No cargues los datos demo en producción.**

---

## 🧑‍💻 Desarrollo

### Estructura
- `src/` contiene las clases del namespace `App\` (PSR-4): `Http` (Request, Router), `Controller`, `Repository` (proyectos, tareas, agencias, invitaciones), `Service` (altas de cuentas), `Security` (permisos, CSRF, límite de intentos de login), `Upload`, `Database` (migraciones) y `Support`.
- `config/app.php` mantiene las funciones globales de siempre (`canManageTasks()`, `csrf_token()`, ...) como fachada sobre esas clases.
- `api/tasks.php` usa el patrón Router + Controller (`App\Controller\TaskController`). El resto de endpoints de `api/` sigue siendo procedural y se puede migrar al mismo patrón.
- `project-view.php` arma los datos de la página; el HTML de cada pestaña y modal está en `includes/project/` y su JavaScript en `assets/js/project-view.js`.

### Dependencias de desarrollo
Composer y Node solo hacen falta en desarrollo. En producción no hay que ejecutar `composer install` (existe un autoloader de respaldo en `config/autoload.php`) ni `npm` (el CSS compilado se versiona).
```bash
composer install
```
```bash
npm install
```

### Estilos (Tailwind CSS)
Las clases se compilan desde `assets/css/input.css` a `assets/css/app.css`, que es el archivo que se sube al servidor. **Después de añadir o cambiar clases en PHP o JS, recompila y versiona `app.css`**:
```bash
npm run build:css
```
Durante el desarrollo puedes dejarlo recompilando automáticamente:
```bash
npm run watch:css
```

### Tests
Los tests unitarios no necesitan base de datos:
```bash
vendor/bin/phpunit --testsuite unit
```
Los de integración usan una base MySQL desechable: **la base indicada en `TEST_DB_NAME` se borra y se recrea en cada ejecución**, por eso su nombre debe contener `test`. Sin `TEST_DB_NAME` se omiten. Los datos de conexión se toman de `TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_USER` y `TEST_DB_PASS` (por defecto `127.0.0.1:3306`, `root`, sin contraseña; ver `phpunit.xml`).
```bash
TEST_DB_NAME=flowtasks_phpunit_test vendor/bin/phpunit
```
