<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/bootstrap.php';
$path = trim($_SERVER['PATH_INFO'] ?? '', '/');
try {
 if ($path === 'health') respond(['success'=>true,'application'=>'Liveo','database'=>'not_checked','provider'=>'Matchora']);
 if ($path === 'schedule') {
   $date = $_GET['date'] ?? date('Y-m-d');
   if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) respond(['error'=>'Fecha inválida'],400);
   $query = http_build_query(['date'=>$date] + array_intersect_key($_GET,['watchable'=>true,'live'=>true]));
   $data = matchora('schedule?'.$query);
   respond(['success'=>true,'events'=>$data['events'] ?? []]);
 }
 if ($path === 'live') { $data=matchora('live'); respond(['success'=>true,'events'=>$data['events'] ?? []]); }
 if ($path === 'channels') { $data=matchora('channels'); respond(['success'=>true,'channels'=>$data['channels'] ?? []]); }
 if (preg_match('#^events/([A-Za-z0-9_-]+)$#',$path,$m)) respond(['success'=>true,'event'=>matchora('events/'.rawurlencode($m[1]))]);
 respond(['error'=>'Endpoint no encontrado'],404);
} catch (Throwable $e) { respond(['success'=>false,'error'=>'No se pudo obtener información deportiva','detail'=>$e->getMessage()],502); }
