<?php
/**
 * SISTEMA FACTUS ERP - VERSIÓN PHP (SIN BASE DE DATOS)
 * Almacenamiento basado en archivos .txt
 */

session_start();

// ==========================================
// 1. CONFIGURACIÓN DEL SISTEMA LOCAL
// ==========================================
$ADMIN_USER = 'jz300703@gmail.com';
$ADMIN_PASS = 'admin123'; // ¡Cambia esta contraseña cuando lo subas a tu servidor!

// Directorio donde se guardarán los archivos .txt
$DATA_DIR = __DIR__ . '/datos_txt/';
if (!is_dir($DATA_DIR)) {
    mkdir($DATA_DIR, 0777, true);
}

// ==========================================
// 2. CONFIGURACIÓN API FACTUS (SANDBOX)
// ==========================================
$FACTUS_URL = 'https://api-sandbox.factus.com.co';
$FACTUS_CLIENT_ID = '9de9ec14-5684-48b5-baa3-9a87fc8c3c42';
$FACTUS_CLIENT_SECRET = 'bxKg2MEoRngRre8bpMWvH9JRLWan05sOofFghjcS';
$FACTUS_USER = 'sandbox@factus.com.co';
$FACTUS_PASS = 'sandbox2024%';

// ==========================================
// 3. LÓGICA DE AUTENTICACIÓN LOCAL (auth)
// ==========================================
if (isset($_POST['login_local'])) {
    if ($_POST['email'] === $ADMIN_USER && $_POST['password'] === $ADMIN_PASS) {
        $_SESSION['logged_in'] = true;
        header("Location: ?page=dashboard");
        exit;
    } else {
        $login_error = "Credenciales incorrectas.";
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: ?");
    exit;
}

// ==========================================
// 4. MOTOR DE ALMACENAMIENTO EN ARCHIVOS .TXT
// ==========================================
function guardar_en_txt($nombre_archivo, $datos) {
    global $DATA_DIR;
    file_put_contents($DATA_DIR . $nombre_archivo, json_encode($datos, JSON_PRETTY_PRINT));
}

function leer_de_txt($nombre_archivo, $tiempo_vida_segundos = 3600) {
    global $DATA_DIR;
    $ruta = $DATA_DIR . $nombre_archivo;
    
    // Si el archivo existe y no ha expirado su tiempo de vida
    if (file_exists($ruta) && (time() - filemtime($ruta)) < $tiempo_vida_segundos) {
        $contenido = file_get_contents($ruta);
        return json_decode($contenido, true);
    }
    return false; // Retorna falso si no existe o expiró, forzando a llamar a la API
}

// ==========================================
// 5. FUNCIONES DE COMUNICACIÓN CON FACTUS (cURL)
// ==========================================
function obtener_token_factus() {
    global $FACTUS_URL, $FACTUS_CLIENT_ID, $FACTUS_CLIENT_SECRET, $FACTUS_USER, $FACTUS_PASS;
    
    // Revisar si ya tenemos un token guardado en .txt que sea válido (dura aprox 24h, usamos 12h)
    $token_guardado = leer_de_txt('factus_token.txt', 43200);
    if ($token_guardado && isset($token_guardado['access_token'])) {
        return $token_guardado['access_token'];
    }

    // Si no hay token, solicitamos uno nuevo
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $FACTUS_URL . '/oauth/token');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'password',
        'client_id' => $FACTUS_CLIENT_ID,
        'client_secret' => $FACTUS_CLIENT_SECRET,
        'username' => $FACTUS_USER,
        'password' => $FACTUS_PASS
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $respuesta = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 200) {
        $datos_token = json_decode($respuesta, true);
        guardar_en_txt('factus_token.txt', $datos_token); // Guardamos el nuevo token
        return $datos_token['access_token'];
    }
    
    return null;
}

function consultar_api_factus($endpoint, $archivo_cache) {
    global $FACTUS_URL;
    
    // Intentar leer de nuestro almacenamiento local (.txt) primero para no saturar la API
    $datos_cacheados = leer_de_txt($archivo_cache, 1800); // Caché de 30 minutos
    if ($datos_cacheados !== false && isset($_GET['refresh']) == false) {
        return $datos_cacheados;
    }

    $token = obtener_token_factus();
    if (!$token) return ['error' => 'No se pudo obtener el token de autenticación de Factus.'];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $FACTUS_URL . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json'
    ]);

    $respuesta = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $datos = json_decode($respuesta, true);
    
    if ($http_code == 200) {
        guardar_en_txt($archivo_cache, $datos); // Guardar respuesta en .txt
        return $datos;
    }
    
    return ['error' => 'Error de la API: ' . $http_code];
}

// ==========================================
// 6. ENRUTAMIENTO (Controlador Frontal)
// ==========================================
$page = isset($_GET['page']) ? $_GET['page'] : 'login';
if (isset($_SESSION['logged_in']) && $page == 'login') {
    $page = 'dashboard';
}

// Obtener datos si estamos en una página que lo requiere
$api_data = null;
if (isset($_SESSION['logged_in'])) {
    switch ($page) {
        case 'facturas':
            $api_data = consultar_api_factus('/v1/bills', 'facturas.txt');
            break;
        case 'tributos':
            $api_data = consultar_api_factus('/v1/taxes', 'tributos.txt');
            break;
        case 'municipios':
            $api_data = consultar_api_factus('/v1/municipalities', 'municipios.txt');
            break;
        case 'paises':
            $api_data = consultar_api_factus('/v1/countries', 'paises.txt');
            break;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Factus ERP - PHP System</title>
    <!-- Tailwind CSS para el diseño -->
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-800 font-sans antialiased">

<?php if (!isset($_SESSION['logged_in'])): ?>
    <!-- ========================================== -->
    <!-- PANTALLA DE LOGIN                          -->
    <!-- ========================================== -->
    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="bg-white max-w-md w-full rounded-2xl shadow-xl overflow-hidden border border-gray-100">
            <div class="bg-blue-600 p-8 text-center">
                <h1 class="text-3xl font-bold text-white">Factus ERP</h1>
                <p class="text-blue-200 mt-2">Acceso al Sistema PHP</p>
            </div>
            
            <div class="p-8">
                <?php if(isset($login_error)): ?>
                    <div class="bg-red-50 text-red-600 p-3 rounded-lg mb-4 text-sm font-medium border border-red-200">
                        <?php echo $login_error; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="?page=login" class="space-y-5">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Usuario (Email)</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($ADMIN_USER); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Contraseña</label>
                        <input type="password" name="password" placeholder="Ingresa tu contraseña" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none" required>
                    </div>
                    <button type="submit" name="login_local" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg transition-colors mt-4">
                        Entrar al Sistema
                    </button>
                </form>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- ========================================== -->
    <!-- DASHBOARD PRINCIPAL                        -->
    <!-- ========================================== -->
    <div class="min-h-screen flex flex-col md:flex-row">
        
        <!-- BARRA LATERAL (SIDEBAR) -->
        <aside class="w-full md:w-64 bg-gray-900 text-gray-300 flex flex-col shrink-0">
            <div class="h-16 flex items-center px-6 border-b border-gray-800 bg-black">
                <span class="font-bold text-white text-lg tracking-wide">FACTUS ERP (PHP)</span>
            </div>
            
            <div class="p-4 flex-1">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2 px-2">Documentos</p>
                <nav class="space-y-1">
                    <a href="?page=dashboard" class="block px-4 py-2 rounded-lg <?php echo $page == 'dashboard' ? 'bg-blue-600 text-white' : 'hover:bg-gray-800'; ?>">Inicio</a>
                    <a href="?page=facturas" class="block px-4 py-2 rounded-lg <?php echo $page == 'facturas' ? 'bg-blue-600 text-white' : 'hover:bg-gray-800'; ?>">Facturas (Bills)</a>
                </nav>

                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mt-8 mb-2 px-2">Catálogos API</p>
                <nav class="space-y-1">
                    <a href="?page=tributos" class="block px-4 py-2 rounded-lg <?php echo $page == 'tributos' ? 'bg-blue-600 text-white' : 'hover:bg-gray-800'; ?>">Tributos</a>
                    <a href="?page=municipios" class="block px-4 py-2 rounded-lg <?php echo $page == 'municipios' ? 'bg-blue-600 text-white' : 'hover:bg-gray-800'; ?>">Municipios</a>
                    <a href="?page=paises" class="block px-4 py-2 rounded-lg <?php echo $page == 'paises' ? 'bg-blue-600 text-white' : 'hover:bg-gray-800'; ?>">Países</a>
                </nav>
            </div>

            <div class="p-4 border-t border-gray-800 bg-gray-900">
                <div class="text-xs text-gray-400 mb-2 truncate">Usuario: <?php echo $ADMIN_USER; ?></div>
                <a href="?logout=1" class="block text-center text-red-400 hover:text-red-300 font-medium py-2 rounded border border-red-900 hover:bg-red-900/30 transition">
                    Cerrar Sesión
                </a>
            </div>
        </aside>

        <!-- ÁREA PRINCIPAL -->
        <main class="flex-1 flex flex-col min-w-0 bg-gray-50 overflow-hidden">
            
            <!-- ENCABEZADO SUPERIOR -->
            <header class="h-16 bg-white border-b border-gray-200 flex items-center justify-between px-8 shrink-0 shadow-sm">
                <h2 class="text-xl font-bold text-gray-800 capitalize">
                    <?php echo htmlspecialchars($page); ?>
                </h2>
                <div class="flex items-center gap-3">
                    <?php if($page != 'dashboard'): ?>
                        <a href="?page=<?php echo $page; ?>&refresh=1" class="text-sm bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded-md font-medium border border-gray-200 transition">
                            Actualizar desde API
                        </a>
                    <?php endif; ?>
                    <span class="flex items-center gap-2 text-sm text-green-600 bg-green-50 px-3 py-1.5 rounded-full border border-green-200">
                        <span class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></span>
                        Sandbox OK
                    </span>
                </div>
            </header>

            <!-- CONTENIDO DE LA PÁGINA -->
            <div class="flex-1 overflow-auto p-8">
                
                <?php if(isset($api_data['error'])): ?>
                    <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 rounded shadow-sm mb-6">
                        <p class="font-bold">Error de Conexión API</p>
                        <p><?php echo $api_data['error']; ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($page == 'dashboard'): ?>
                    <!-- VISTA INICIO -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                        <div class="bg-white p-6 rounded-xl border border-gray-200 shadow-sm">
                            <h3 class="font-bold text-lg text-gray-800 border-b pb-2 mb-4">Información del Sistema</h3>
                            <ul class="space-y-3 text-sm text-gray-600">
                                <li><strong>Motor:</strong> PHP 7.4+</li>
                                <li><strong>Base de datos:</strong> Archivos planos (.txt)</li>
                                <li><strong>Directorio Datos:</strong> <code class="bg-gray-100 px-1 rounded">/datos_txt/</code></li>
                                <li><strong>Autenticación API:</strong> OAuth2 Bearer (Token Auto-generado)</li>
                            </ul>
                        </div>
                        <div class="bg-white p-6 rounded-xl border border-gray-200 shadow-sm border-t-4 border-t-blue-500">
                            <h3 class="font-bold text-lg text-gray-800 border-b pb-2 mb-4">Credenciales Activas</h3>
                            <ul class="space-y-3 text-sm text-gray-600">
                                <li><strong>Usuario:</strong> <?php echo $ADMIN_USER; ?></li>
                                <li><strong>Factus Enpoint:</strong> https://api-sandbox.factus.com.co</li>
                                <li><strong>Email Factus:</strong> <?php echo $FACTUS_USER; ?></li>
                            </ul>
                        </div>
                    </div>

                <?php elseif (in_array($page, ['facturas', 'tributos', 'municipios', 'paises'])): ?>
                    <!-- VISTA DE TABLAS / DATOS DE LA API -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                        <?php if (isset($api_data['data']) && is_array($api_data['data'])): ?>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-sm text-gray-600">
                                    <thead class="bg-gray-50 text-gray-500 font-semibold border-b border-gray-200">
                                        <tr>
                                            <th class="px-6 py-4">ID / Referencia</th>
                                            <th class="px-6 py-4">Nombre / Detalle</th>
                                            <th class="px-6 py-4">Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php foreach ($api_data['data'] as $item): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-6 py-4 font-medium text-gray-900">
                                                    <?php echo isset($item['id']) ? $item['id'] : (isset($item['number']) ? $item['number'] : 'N/A'); ?>
                                                </td>
                                                <td class="px-6 py-4">
                                                    <?php echo isset($item['name']) ? $item['name'] : (isset($item['nombre']) ? $item['nombre'] : json_encode($item)); ?>
                                                </td>
                                                <td class="px-6 py-4">
                                                    <button class="text-blue-600 hover:underline text-xs font-bold uppercase">Ver JSON</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if(empty($api_data['data'])): ?>
                                            <tr><td colspan="3" class="px-6 py-8 text-center text-gray-500">No hay datos registrados en este endpoint.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="p-12 text-center text-gray-500">
                                No se encontraron datos o hubo un error al procesar el JSON de Factus.
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            </div>
        </main>
    </div>
<?php endif; ?>

</body>
</html>

