<?php
// api/tasks.php - API de tareas. La lógica está en App\Controller\TaskController.
require_once __DIR__ . '/bootstrap.php';

use App\Controller\TaskController;
use App\Http\Router;
use App\Repository\ProjectRepository;
use App\Repository\TaskRepository;

$router = new Router();
(new TaskController($user, new ProjectRepository(getDB()), new TaskRepository(getDB())))->register($router);
$router->dispatch($request);
