<?php

include_once __DIR__ . "/Store.php";

$Store = new Store(false);
$Store->init();

$sku = $_POST["sku"];
$amount = intval($_POST["amount"]);

if(empty($sku)) die("Please supply all values");
if(!in_array($sku, array_keys((array)$Store->get("products")))) die("Invalid sku");
if($amount < 0) die("Amount must be greater or equal to 0");

$cart = isset($_COOKIE["cart"]) ? json_decode($_COOKIE["cart"]) : (object) array();

$cart->{$sku} = $amount;
if($amount == 0) unset($cart->{$sku});

setCookie("cart", json_encode($cart), strtotime("+1 year"), "/");

header("location: {$Store->get("config", "base_url")}{$Store->slug}/cart");
