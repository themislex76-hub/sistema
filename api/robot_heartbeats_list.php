<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/robot_helpers.php';

// Panel nuevo en "Equipo" (20 nov 2026), pedido explícito del usuario
// tras descubrir a mano (con schtasks en su máquina de Windows) que 3 de
// los 5 robots llevaban sin correr por un run_diario.bat faltante: "y
// podemos poner algo en el sistema que nos avise cuando fue la última
// vez que corrieron los robots". Solo Administrador.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.', 405);
require_admin();

respond(['data' => robots_estado(db())]);
