<?php

global $Wcms;

include_once __DIR__ . "/Product.php";

class Store {

    private $Wcms = false;

    private $db;

    private $dbPath = __DIR__ . "/simplestore.json";

	private $path = [];

	public $slug = "store";

	public $currency = "EUR";
	public $symbol = "&euro;";

	private $active = false;

	public $cart = [];

	private $payment_methods = [];

	public function __construct($load) {
        if($load) {
            global $Wcms;
            $this->Wcms = &$Wcms;
			$Wcms->Store = &$this;
        }
	}

    public function init() : void {
        $this->db = $this->getDb();
		$this->payment_methods = (array)$this->get("config")->methods;
		$this->cart = isset($_COOKIE["cart"]) ? json_decode($_COOKIE["cart"]) : (object)[];
		if($this->Wcms) $this->set("config", "base_url", $this->Wcms->url());
    }

    private function getDb() : stdClass {
		if (!file_exists($this->dbPath)) {
			file_put_contents($this->dbPath, json_encode([
				"products" => [],
				"orders" => []
            ], JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
		}
		return json_decode(file_get_contents($this->dbPath));
    }

    public function attach() : void {
        $this->Wcms->addListener('settings', [$this, "settingsListener"]);
        $this->Wcms->addListener('css', [$this, "cssListener"]);
        $this->Wcms->addListener('css', [$this, "startListener"]);
		$this->Wcms->addListener('page', [$this, "tagListener"]);
		$this->Wcms->addListener('block', [$this, "tagListener"]);
		$this->Wcms->addListener('page', [$this, "pageListener"]);
		$this->Wcms->addListener('page', [$this, "setMetaTags"]);

		// Test if current page exists, change header accordingly
		$pathTest = explode('-', $this->Wcms->currentPage);
        if (array_shift($pathTest) === $this->slug) {
            $headerResponse = 'HTTP/1.0 200 OK';

            if ($pathTest) {
				$page = array_shift($pathTest);
                $path = implode('-', $pathTest);
                if (($page == "product" && !property_exists($this->db->products, strtoupper($path))) || !in_array($page, ["product", "cart", "checkout", "complete"])) {
                    $headerResponse = 'HTTP/1.0 404 Not Found';
                }
            }
            global $Wcms;
            $Wcms->headerResponseDefault = false;
            $Wcms->headerResponse = $headerResponse;
        }
    }

    private function save() : void {
        file_put_contents($this->dbPath, json_encode($this->db, JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public function set() : void {
		$numArgs = func_num_args();
		$args = func_get_args();

		switch ($numArgs) {
			case 2:
				$this->db->{$args[0]} = $args[1];
				break;
			case 3:
				$this->db->{$args[0]}->{$args[1]} = $args[2];
				break;
			case 4:
				$this->db->{$args[0]}->{$args[1]}->{$args[2]} = $args[3];
				break;
			case 5:
				$this->db->{$args[0]}->{$args[1]}->{$args[2]}->{$args[3]} = $args[4];
				break;
		}
		$this->save();
	}

    public function get() {
		$numArgs = func_num_args();
		$args = func_get_args();
		switch ($numArgs) {
			case 1:
				return $this->db->{$args[0]};
			case 2:
				return $this->db->{$args[0]}->{$args[1]};
			case 3:
				return $this->db->{$args[0]}->{$args[1]}->{$args[2]};
			case 4:
				return $this->db->{$args[0]}->{$args[1]}->{$args[2]}->{$args[3]};
			case 5:
				return $this->db->{$args[0]}->{$args[1]}->{$args[2]}->{$args[3]}->{$args[4]};
		}
	}

	public function startListener(array $args): array {
		$path = explode("-", $this->Wcms->currentPage);
        if (array_shift($path) == $this->slug) {
            $this->active = true;
            $this->path = $path ? [array_shift($path), implode("-", $path)] : [""];
        }

		if ($this->active) {
            // Remove page doesn't exist notice on store pages
            if (isset($_SESSION['alert']['info'])) {
                foreach ($_SESSION['alert']['info'] as $i => $v) {
                    if (strpos($v['message'], 'This page ') !== false && strpos($v['message'], ' doesn\'t exist.</b> Click inside the content below to create it.') !== false) {
                        unset($_SESSION['alert']['info'][$i]);
                    }
                }
            }
        }

		return $args;
	}

	public function tagListener(array $args): array {
		if($this->Wcms->loggedIn) return $args;

		$args[0] = preg_replace_callback("/\[product( |)(sku=([a-z0-9\-]*)|)\]/i", function($line) {
			$sku = $line[3];

			if(!property_exists($this->db->products, $sku)) {
				// Didn't supply a valid sku
				return $line[0];
			}

			$product = $this->db->products->{$sku};
			$price = money_format('%.2n', $product->price);
			$amount = 0;
			if(property_exists($this->cart, $sku)) $amount = $this->cart->{$sku};
			$amount++;

			return <<<HTML
			<!-- {$line[0]} --><div class="store product tile">
				<h4 class="product name">{$product->name}</h2>
				<p class="product price">{$this->symbol}{$price}</p>
				<p class="product image"><img src="{$this->Wcms->url($product->image)}" style="max-width:200px;"></p>
				<form method="post" action="{$this->Wcms->url('plugins/simplestore/add_to_cart.php')}" class="form-inline">
					<input type="hidden" class="form-control w3-input" name="sku" value="$sku" placeholder="Amount" required>
					<input type="hidden" class="form-control w3-input" name="amount" value="{$amount}" min="1" placeholder="Amount" required>
					<button type="submit" class="btn btn-default">Add to cart</button> &nbsp;
					<a href="{$this->Wcms->url("{$this->slug}/product/$sku")}">More info</a>
				</form>
			</div>
HTML;
		}, $args[0]);

		return $args;
	}

	public function pageListener(array $args): array {
		if(!$this->active) return $args;
		if($args[1] != "content") return $args;


		if($this->path[0] == "product") {
			// Product page
			$sku = strtoupper($this->path[1]);

			if(!property_exists($this->db->products, $sku)) {
				// Didn't supply a valid sku
				$args[0] = $this->Wcms->get('pages', '404')->content;
				return $args;
			}

			$product = $this->db->products->{$sku};
			$price = money_format('%.2n', $product->price);
			$amount = 1;
			if(property_exists($this->cart, $sku)) $amount = $this->cart->{$sku};
			$button = "Add to cart";
			if($amount > 1) $button = "Update cart";

			$args[0] = <<<HTML

			<h2 class="product name">{$product->name}</h2>
			<div style="font-size:1.5em; color:#09f;">{$this->symbol}{$price}</div>
			<div><img src="{$this->Wcms->url($product->image)}" style="max-width:400px"></div>
			<div>{$product->desc}</div>
			<form method="post" action="{$this->Wcms->url('plugins/simplestore/add_to_cart.php')}" class="form-inline">
				<input type="hidden" class="form-control w3-input" name="sku" value="$sku" placeholder="Amount" required>
				<div class="form-group">
					<input type="number" class="form-control w3-input" name="amount" value="{$amount}" min="1" placeholder="Amount" required>
				</div>
				<button type="submit" class="btn btn-default">$button</button>
			</form>
			<br><br><br>
HTML;
		} else if($this->path[0] == "cart") {
			// Cart page
			$args[0] = "<h2 class='store-title'>Shopping cart</h2>";
			$args[0] .= "<table width='100%' class='shop-cart'>";

			$sub_total = 0;
			foreach($this->cart as $sku => $amount) {
				if(!property_exists($this->db->products, $sku)) continue;

				$product = $this->db->products->{$sku};
				$price = money_format('%.2n', $product->price);
				$total = money_format('%.2n', $product->price * $amount);
				$sub_total += $product->price * $amount;

				$args[0] .= <<<HTML
				<tr>
					<td><img src='{$this->Wcms->url($product->image)}' style='width:50px; height:50px; object-fit:cover;'></td>
					<td><a href="{$this->Wcms->url("{$this->slug}/product/$sku")}">{$product->name} <span style='opacity:0.5'>$sku</span></a></td>
					<td><form method="post" action="{$this->Wcms->url('plugins/simplestore/add_to_cart.php')}">
						<input type="hidden" name="sku" value="$sku">
						<div class="form-group" style="display:inline-block; vertical-align:middle; margin-bottom:0">
							<input type="number" class="form-control w3-input" name="amount" min="0" value="$amount" onchange="this.parentElement.parentElement.submit()">
						</div>
					</form></td>
					<td align="right">{$this->symbol}$total</td>
				</tr>
HTML;
			}

			$sub_total_formatted = money_format('%.2n', $sub_total);
			$args[0] .= <<<HTML
				<tr>
					<td colspan="2" height="50"></td>
					<td align="right">Total:</td>
					<td align="right"><strong>{$this->symbol}$sub_total_formatted</strong></td>
				</tr>

				<tr>
					<td colspan="4" align="right">
						<a class="btn btn-primary w3-button w3-black" href="{$this->Wcms->url("{$this->slug}/checkout")}">Checkout</a>
					</td>
				</tr>

			</table>
HTML;
		} else if($this->path[0] == "checkout") {
			// Checkout page

			$args[0] = "<p>Here someone will have to type all of their info, and continue to payment. The payment
			will be optional (as in can be disabled by not having any payment methods installed). The payment methods
			can be installed by adding a new plugin, that get's loaded after this one. For this some naming scheme has
			to be used, where the payment plugin starts with `simplestore-`, so it get's loaded after `simplestore`.</p>";


			// Overview
			$cart = "<table width='100%'>";
			$sub_total = 0;
			foreach($this->cart as $sku => $amount) {
				if(!property_exists($this->db->products, $sku)) continue;
				$product = $this->db->products->{$sku};
				$cart .= <<<HTML
				<tr>
					<td><img src='{$this->Wcms->url($product->image)}' style='width:50px; height:50px; object-fit:cover;'></td>
					<td><a href="{$this->Wcms->url("{$this->slug}/product/$sku")}">{$product->name} <span style='opacity:0.5'>$sku</span></a></td>
					<td>$amount&times;</td>
				</tr>
HTML;
				$sub_total += $product->price * $amount;
			}
			$sub_total_formatted = money_format('%.2n', $sub_total);
			$cart .= <<<HTML
				<tr>
					<td height="50"></td>
					<td align="right">Total: &nbsp; </td>
					<td><strong>{$this->symbol}$sub_total_formatted</strong></td>
				</tr>

			</table>
HTML;

			// Payment method
			$payment_methods = "<h4>Payment method:</h4>";
			foreach($this->db->config->methods as $method => $config) {
				$payment_methods .= <<<HTML
					<label>
						<input type="radio" name="payment_method" value="$method" required> $method
					</label><br />
HTML;
			}

			// Form
			$args[0] .= <<<HTML
			<div class="store checkout">
				<h2 class='store-title'>Checkout</h2><br>
				<form method="post" action="{$this->Wcms->url('plugins/simplestore/checkout.php')}" class="row w3-row-padding">
					<div class="form-group col-xs-6 w3-half">
						<label for="first_name">First Name</label>
						<input type="text" class="form-control w3-input" id="first_name" name="first_name" placeholder="John" required>
					</div>
					<div class="form-group col-xs-6 w3-half">
						<label for="last_name">Last Name</label>
						<input type="text" class="form-control w3-input" id="last_name" name="last_name" placeholder="Doe" required>
					</div>
					<div class="form-group col-xs-12 w3-col">
						<label for="email">Email Address</label>
						<input type="email" class="form-control w3-input" id="email" name="email" placeholder="john.doe@example.com" required>
						<span class="help-block w3-opacity">You will recieve your order confirmation by email.</span><br>
					</div>
					<div class="form-group col-xs-12 w3-col">
						<label for="address">Shipping Address</label>
						<p><input type="address" class="form-control w3-input" id="address" name="address" placeholder="Address Line 1" required></p>
						<input type="address" class="form-control w3-input" id="address2" name="address2" placeholder="Address Line 2 (optional)">
					</div>
					<div class="form-group col-xs-4 w3-third">
						<label for="country">Country</label>
						<select class="form-control w3-input" id="country" name="country" required>
							<option value="">Choose...</option>
							<option>United States</option>
						</select>
					</div>
					<div class="form-group col-xs-4 w3-third">
						<label for="state">State</label>
						<input type="text" class="form-control w3-input" id="state" name="state" placeholder="State">
					</div>
					<div class="form-group col-xs-4 w3-third">
						<label for="zip">Zip</label>
						<input type="text" class="form-control w3-input" id="zip" name="zip" placeholder="Zip">
					</div>
					<div class="form-group col-xs-12 w3-col">
						<br>
						$payment_methods
						<br>
						<h2>Summary</h2><br>
						$cart
						<br><br>
						<input type="submit" class="btn btn-primary w3-button w3-black" value="Place order">
					</div>
				</form><br><br><br><br><br><br><br>
			</div>
HTML;
		} else if($this->path[0] == "complete") {
			$order_id = htmlentities($_GET["order_id"]);
			$args[0] = <<<HTML
			<div class="store order-complete">
				<h2>Order complete!</h2>
				<p>Your order id is <b>{$order_id}</b></p>
				<p>You can take a look at your order at the following link: <a href="{$this->Wcms->url("store/order/$order_id")}">{$this->Wcms->url("store/order/$order_id")}</a>
			</div>
HTML;
		} else if($this->path[0] == "order") {
			$order_id = htmlentities($this->path[1]);

			if(!in_array($order_id, (array)$this->db->orders)) {
				$args[0] = $this->Wcms->get('pages', '404')->content;
				return $args;
			}

			$order = json_decode(file_get_contents(__DIR__ . "/orders/$order_id.json"));

			$cart = "<table width='100%' class='store shop-cart'>";
			foreach($order->order->items as $product) {
				$total = money_format('%.2n', $product->product->price * $product->amount);
				$cart .= <<<HTML
				<tr>
					<td><img src='{$this->Wcms->url($product->product->image)}' style='width:50px; height:50px; object-fit:cover;'></td>
					<td><a href="{$this->Wcms->url("{$this->slug}/product/$product->sku")}">{$product->product->name} <span style='opacity:0.5'>$product->sku</span></a></td>
					<td>$product->amount&times;</td>
					<td align="right">{$this->symbol}$total</td>
				</tr>
HTML;
			}
			$sub_total_formatted = money_format('%.2n', $order->order->total);
			$tax_total_formatted = money_format('%.2n', $order->order->tax);
			$cart .= <<<HTML
				<tr>
					<td height="50" colspan="2"></td>
					<td align="right">Tax:</td>
					<td align="right"><strong>{$this->symbol}$tax_total_formatted</strong></td>
				</tr>
				<tr>
					<td colspan="2"></td>
					<td align="right">Total:</td>
					<td align="right"><strong>{$this->symbol}$sub_total_formatted</strong></td>
				</tr>

			</table>
HTML;

			$date = Date("Y-m-d H:i", $order->date);
			$args[0] = <<<HTML
			<div class="store order">
				<h2>Order #$order_id</h2>
				<p>$date / {$order->payment->status}</p><br>
				<p><b>{$order->customer->first_name} {$order->customer->last_name}</b><br />
					{$order->customer->email}</p>
				<p>{$order->address->address}<br>
					{$order->address->zip}, {$order->address->state}<br>
					{$order->address->country}</p><br>
				$cart
			</div>
HTML;
		} else {
			$args[0] = $this->Wcms->get('pages', '404')->content;
		}

		return $args;
	}

	public function cssListener(array $args): array {
		$args[0] .= "<link rel='stylesheet' href='{$this->Wcms->url('plugins/simplestore/css/style.css')}'>";
		if(!$this->Wcms->loggedIn) return $args;
		$args[0] .= "<link rel='stylesheet' href='{$this->Wcms->url('plugins/simplestore/css/admin.css')}'>";

		return $args;
	}

	public function registerPaymentMethod(string $name, string $plugin, string $process): void {
		$this->payment_methods[$name] = ["plugin" => $plugin, "process" => $process];

		$this->set("config", "methods", $this->payment_methods);
	}

	public function setMetaTags(array $args): array {
		// Check if current page is part of the slug
		if(substr($this->Wcms->currentPage, 0, strlen($this->slug)) != $this->slug) return $args;

		if($args[1] == "title") $args[0] = "Store";
		if($args[1] == "keywords") $args[0] = implode(", ", explode("-", $this->Wcms->currentPage));

		$path = explode("-", $this->Wcms->currentPage);
		array_shift($path);
		$page = array_shift($path);
		if($page == "product") {
			$sku = strtoupper(implode("-", $path));
			if(property_exists($this->db->products, $sku)) {
				if($args[1] == "description") $args[0] = $this->db->products->{$sku}->short_desc;
				if($args[1] == "title") $args[0] = "{$this->db->products->{$sku}->name} - Store";
			}
		} else if($page == "cart") {
			if($args[1] == "title") $args[0] = "Your Cart";
			if($args[1] == "description") $args[0] = "Your shopping cart";
		}


		return $args;
	}

	public function settingsListener(array $args): array {
		$doc = new DOMDocument();
		@$doc->loadHTML($args[0]);

		$menuItem = $doc->createElement("li");
		$menuItem->setAttribute("class", "nav-item");
		$menuItemA = $doc->createElement("a");
		$menuItemA->setAttribute("href", "#store");
		$menuItemA->setAttribute("aria-controls", "store");
		$menuItemA->setAttribute("role", "tab");
		$menuItemA->setAttribute("data-toggle", "tab");
		$menuItemA->setAttribute("class", "nav-link");
		$menuItemA->nodeValue = "Store";
		$menuItem->appendChild($menuItemA);

		$doc->getElementById("currentPage")->parentNode->parentNode->childNodes->item(1)->appendChild($menuItem);

		$wrapper = $doc->createElement("div");
		$wrapper->setAttribute("role", "tabpanel");
		$wrapper->setAttribute("class", "tab-pane");
		$wrapper->setAttribute("id", "store");

		// Contents of wrapper

		// Menu Items

		$tabs = $doc->createElement("ul");
        $tabs->setAttribute("class", "nav nav-tabs");
        $tabs->setAttribute("role", "tablist");

        $li = $doc->createElement("li");
        $li->setAttribute("role", "presentation");
        $li->setAttribute("class", "active");

        $a = $doc->createElement("a");
        $a->setAttribute("href", "#store_products");
        $a->setAttribute("aria-controls", "store_products");
        $a->setAttribute("role", "tab");
        $a->setAttribute("data-toggle", "tab");
        $a->nodeValue = "Products";

        $li->appendChild($a);

        $tabs->appendChild($li);


        $li = $doc->createElement("li");
        $li->setAttribute("role", "presentation");

        $a = $doc->createElement("a");
        $a->setAttribute("href", "#store_orders");
        $a->setAttribute("aria-controls", "store_orders");
        $a->setAttribute("role", "tab");
        $a->setAttribute("data-toggle", "tab");
        $a->nodeValue = "Orders";

        $li->appendChild($a);

        $tabs->appendChild($li);


        $li = $doc->createElement("li");
        $li->setAttribute("role", "presentation");

        $a = $doc->createElement("a");
        $a->setAttribute("href", "#store_settings");
        $a->setAttribute("aria-controls", "store_settings");
        $a->setAttribute("role", "tab");
        $a->setAttribute("data-toggle", "tab");
        $a->nodeValue = "Settings";

        $li->appendChild($a);

        $tabs->appendChild($li);

        $wrapper->appendChild($tabs);

		// End of menu items

		// Contents of menu pages

		$pages = $doc->createElement("div");
        $pages->setAttribute("class", "tab-content");

        $page = $doc->createElement("div");
        $page->setAttribute("role", "tabpanel");
        $page->setAttribute("class", "tab-pane active");
        $page->setAttribute("id", "store_products");

		foreach($this->db->products as $sku => $product) {
			$div = $doc->createElement("div");
			$div->setAttribute("class", "product");

			$title = $doc->createElement("h4");
			$title->nodeValue = $product->name;
			$div->appendChild($title);

			$desc = $doc->createElement("p");
			$desc->nodeValue = $product->short_desc;
			$div->appendChild($desc);

			$p = $doc->createElement("p");
			$btn = $doc->createElement("a");
			$btn->setAttribute("class", "btn btn-info btn-sm");
			$btn->setAttribute("href", $this->Wcms->url("store/product/$sku"));
			$btn->nodeValue = "Open";
			$p->appendChild($btn);
			$div->appendChild($p);

			$page->appendChild($div);
		}

        $pages->appendChild($page);

        $page = $doc->createElement("div");
        $page->setAttribute("role", "tabpanel");
        $page->setAttribute("class", "tab-pane");
        $page->setAttribute("id", "store_orders");

		foreach($this->db->orders as $i => $order_id) {
			$order = json_decode(file_get_contents(__DIR__ . "/orders/$order_id.json"));

			$div = $doc->createElement("div");
			$div->setAttribute("class", "product");

			$title = $doc->createElement("h4");
			$title->nodeValue = "{$order->customer->first_name} {$order->customer->last_name}";
			$div->appendChild($title);

			$desc = $doc->createElement("p");
			$desc->nodeValue = Date("d/m/Y H:i", $order->date) . " {$this->symbol}{$order->order->total}";
			$div->appendChild($desc);

			$p = $doc->createElement("p");
			$btn = $doc->createElement("a");
			$btn->setAttribute("class", "btn btn-info btn-sm");
			$btn->setAttribute("href", $this->Wcms->url("store/order/$order_id"));
			$btn->nodeValue = "Open";
			$p->appendChild($btn);
			$div->appendChild($p);

			$page->appendChild($div);
		}

        $pages->appendChild($page);

        $page = $doc->createElement("div");
        $page->setAttribute("role", "tabpanel");
        $page->setAttribute("class", "tab-pane");
        $page->setAttribute("id", "store_settings");

		$page->nodeValue = "Settings";


        $pages->appendChild($page);

        $wrapper->appendChild($pages);

		// End of menu pages

		// End of contents of wrapper

		$doc->getElementById("currentPage")->parentNode->appendChild($wrapper);

		$args[0] = preg_replace('~<(?:!DOCTYPE|/?(?:html|body))[^>]*>\s*~i', '', $doc->saveHTML());
		return $args;
	}

}
