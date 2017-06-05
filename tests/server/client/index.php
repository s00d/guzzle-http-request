<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Illuminate\Http\Request as R;


$app = new Laravel\Lumen\Application(
    realpath(__DIR__.'/../')
);

function build_response(R $request){
    return response()->json([
        'headers' => $request->header(),
        'query' => $request->query(),
        'json' => $request->json()->all(),
        'form_params' => $request->request->all(),
    ], $request->header('Z-Status', 200));
}

//var_dump($app->router);
$app->get('guzzle-test/get', function (R $req) {
    return build_response($req);
});

$app->post('guzzle-test/post', function (R $req) {
    return build_response($req);
});

$app->put('guzzle-test/put', function (R $req) {
    return build_response($req);
});

$app->patch('guzzle-test/patch', function (R $req) {
    return build_response($req);
});

$app->delete('guzzle-test/delete', function (R $req) {
    return build_response($req);
});

$app->get('guzzle-test/redirect', function (R $req) {
    return redirect('guzzle-test/redirected');
});

$app->get('guzzle-test/redirected', function (R $req) {
    return "Redirected!";
});

$app->get('guzzle-test/simple-response', function (R $req) {
    return "A simple string response";
});

$app->run();