<?php

include_once __DIR__ . "/Store.php";

$Store = new Store(false);
$Store->init();

$first_name = $_POST["first_name"];
$last_name = $_POST["last_name"];
$email = $_POST["email"];
$address = trim("{$_POST["address"]}\n{$_POST["address2"]}");
$country = $_POST["country"];
$state = $_POST["state"];
$zip = $_POST["zip"];
$payment_method = $_POST["payment_method"];
$payment_methods = (array)$Store->get("config", "methods");

if(!in_array($payment_method, array_keys($payment_methods))) die("Invalid payment method");

$cart = isset($_COOKIE["cart"]) ? json_decode($_COOKIE["cart"]) : (object) array();

if(empty($first_name) || empty($last_name) || empty($email) || empty($address) || empty($country) || empty($state) || empty($zip)) die("Please supply all values");
if(count((array)$cart) < 0) die("Must have at least 1 item in cart");

$cart_mapped = [];
$sub_total = 0;
$tax_total = 0;
foreach($cart as $sku => $amount) {
	if(!property_exists($Store->get("products"), $sku)) continue;

	$product = $Store->get("products")->{$sku};
	$sub_total += $product->price * $amount;
	$tax_total += $product->price * $amount * $product->tax / 100;
	$cart_mapped[] = [
		"product" => $product,
		"amount" => $amount,
		"sku" => $sku
	];
}

$order = [
	"customer" => [
		"first_name" => $first_name,
		"last_name" => $last_name,
		"email" => $email
	],
	"address" => [
		"address" => $address,
		"country" => $country,
		"state" => $state,
		"zip" => $zip
	],
	"order" => [
		"total" => $sub_total,
		"tax" => $tax_total,
		"items" => $cart_mapped
	],
	"payment" => [
		"status" => "unpaid"
	],
	"date" => time()
];

$i = 0;
do {
	$order_id = substr(md5(json_encode($order) . $i++ . rand(0, 99999999999999)), 0, 24);
} while (in_array($order_id, (array)$Store->get("orders")));

file_put_contents(__DIR__ . "/orders/$order_id.json", json_encode($order, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$orders = (array)$Store->get("orders");
$orders[] = $order_id;
$Store->set("orders", $orders);

setCookie("cart", false, strtotime("-1 hour"), "/");

if(count($payment_methods) > 0) {
	$method = $payment_methods[$payment_method];
	header("location: {$Store->get("config", "base_url")}/plugins/simplestore-{$method->plugin}/{$method->process}?order_id=$order_id");
} else {
	header("location: {$Store->get("config", "base_url")}{$Store->slug}/complete?order_id=$order_id");
}
