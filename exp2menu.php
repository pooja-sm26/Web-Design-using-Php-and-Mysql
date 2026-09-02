<?php
// ─── DATABASE CONNECTION ───
$host = 'localhost';
$db   = 'bakery_menu';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $products   = $pdo->query("SELECT * FROM products ORDER BY category, name")->fetchAll();
    $categories = $pdo->query("SELECT DISTINCT category FROM products ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    // Demo fallback data if no DB
    $categories = ['Cakes','Cupcakes','Pastries','Cookies','Muffins','Ice Creams','Brownies','Milkshakes','Mojitos','Snacks'];
    $products = [];
    $demoData = [
        // CAKES
        ['Cakes','Chocolate Fudge Cake 1kg','Decadent chocolate cake with ganache frosting',450.00,null,null,false],
        ['Cakes','Red Velvet Cake 1kg','Classic red velvet with cream cheese frosting',420.00,null,null,false],
        ['Cakes','Black Forest Cake 1kg','Chocolate cake with cherries & whipped cream',480.00,null,null,false],
        ['Cakes','Vanilla Sponge Cake 1kg','Light vanilla sponge with fresh cream',380.00,null,null,false],
        ['Cakes','Tiramisu Cake 1kg','Coffee-soaked sponge with mascarpone cream',460.00,null,null,false],
        ['Cakes','Strawberry Cake 1kg','Fresh strawberries with whipped cream',430.00,null,null,false],
        ['Cakes','Butterscotch Cake 1kg','Smooth butterscotch with praline crunch',410.00,null,null,false],
        ['Cakes','Eggless Chocolate Cake 1kg','100% Eggless rich chocolate cake',470.00,null,null,true],
        ['Cakes','Pineapple Cake 1kg','Tropical pineapple with fresh cream frosting',390.00,null,null,false],
        ['Cakes','Mango Delight Cake 1kg','Alphonso mango mousse layered cake',490.00,null,null,false],
        ['Cakes','Lemon Drizzle Cake 1kg','Tangy lemon zest with sugar drizzle',400.00,null,null,false],
        ['Cakes','Carrot Walnut Cake 1kg','Spiced carrot cake with cream cheese frosting',440.00,null,null,false],
        ['Cakes','Death By Chocolate 1kg','Triple chocolate layers with ganache',520.00,null,null,false],
        ['Cakes','Coffee Walnut Cake 1kg','Espresso sponge with walnut praline',455.00,null,null,false],
        ['Cakes','Rose Falooda Cake 1kg','Floral rose with basil seeds & falooda cream',510.00,null,null,true],
        // CUPCAKES
        ['Cupcakes','Red Velvet Cupcakes 6pcs','Classic with cream cheese frosting',250.00,null,null,false],
        ['Cupcakes','Chocolate Cupcakes 6pcs','Double chocolate with ganache',220.00,null,null,false],
        ['Cupcakes','Vanilla Cupcakes 6pcs','Vanilla with buttercream frosting',210.00,null,null,false],
        ['Cupcakes','Oreo Cupcakes 6pcs','Chocolate stuffed with oreo',260.00,null,null,false],
        ['Cupcakes','Lemon Cupcakes 6pcs','Tangy lemon with lemon curd',240.00,null,null,false],
        ['Cupcakes','Strawberry Cupcakes 6pcs','Fresh strawberry pink frosting',245.00,null,null,false],
        ['Cupcakes','Caramel Cupcakes 6pcs','Salted caramel with butterscotch drizzle',255.00,null,null,false],
        ['Cupcakes','Blueberry Cupcakes 6pcs','Blueberry compote with vanilla cream',250.00,null,null,false],
        ['Cupcakes','Nutella Cupcakes 6pcs','Chocolate base with Nutella swirl frosting',270.00,null,null,false],
        ['Cupcakes','Eggless Chocolate Cupcakes 6pcs','100% Eggless double chocolate',230.00,null,null,true],
        // PASTRIES
        ['Pastries','Butter Croissants 4pcs','Fresh French butter croissants',180.00,null,null,false],
        ['Pastries','Almond Croissants 2pcs','Almond cream filled croissants',220.00,null,null,false],
        ['Pastries','Pain au Chocolat 4pcs','Chocolate-filled puff pastry',210.00,null,null,false],
        ['Pastries','Chocolate Eclairs 4pcs','Vanilla cream with chocolate top',280.00,null,null,false],
        ['Pastries','Fruit Tart 1pc','Fresh seasonal fruits with custard cream',320.00,null,null,false],
        ['Pastries','Creme Brulee Tart 2pcs','Caramelized creamy custard tart',260.00,null,null,false],
        ['Pastries','Apple Danish 4pcs','Flaky Danish pastry with cinnamon apple',230.00,null,null,false],
        ['Pastries','Cherry Danish 4pcs','Sweet cherry jam in flaky pastry',235.00,null,null,false],
        ['Pastries','Palmier 6pcs','Crispy caramelized butterfly pastry cookies',190.00,null,null,false],
        ['Pastries','Cannoli 2pcs','Crispy shell with sweet ricotta filling',240.00,null,null,false],
        // COOKIES
        ['Cookies','Chocolate Chip Cookies 12pcs','Classic American style thick cookies',160.00,null,null,false],
        ['Cookies','Peanut Butter Cookies 12pcs','Crunchy peanut butter cookies',170.00,null,null,false],
        ['Cookies','Double Chocolate Cookies 12pcs','Intense double chocolate fudge cookies',175.00,null,null,false],
        ['Cookies','Gingerbread Cookies 12pcs','Spiced gingerbread with icing',165.00,null,null,false],
        ['Cookies','Oatmeal Raisin Cookies 12pcs','Chewy oatmeal with plump raisins',158.00,null,null,false],
        ['Cookies','Almond Biscotti 8pcs','Crispy Italian almond biscotti',180.00,null,null,false],
        ['Cookies','Snickerdoodle Cookies 12pcs','Cinnamon-sugar rolled soft cookies',162.00,null,null,false],
        ['Cookies','White Chocolate Macadamia 12pcs','White chocolate with macadamia nuts',195.00,null,null,false],
        // MUFFINS
        ['Muffins','Blueberry Muffins 2pcs','Jumbo with fresh blueberries',170.00,null,null,false],
        ['Muffins','Banana Chocolate Muffins 2pcs','Moist with chocolate chips',165.00,null,null,false],
        ['Muffins','Chocolate Chip Muffins 2pcs','Rich chocolate dome muffins',180.00,null,null,false],
        ['Muffins','Lemon Poppy Seed Muffins 2pcs','Zesty lemon with poppy seeds',168.00,null,null,false],
        ['Muffins','Carrot Raisin Muffins 2pcs','Wholesome spiced carrot muffins',162.00,null,null,false],
        ['Muffins','Bran Raisin Muffins 2pcs','Hearty bran with sweet raisins',155.00,null,null,false],
        // ICE CREAMS
        ['Ice Creams','Vanilla Ice Cream','Premium Madagascar vanilla ice cream',null,80.00,120.00,false],
        ['Ice Creams','Chocolate Ice Cream','Rich Belgian chocolate ice cream',null,85.00,125.00,false],
        ['Ice Creams','Strawberry Ice Cream','Fresh strawberry chunks ice cream',null,82.00,122.00,false],
        ['Ice Creams','Mango Ice Cream','Alphonso mango ice cream',null,90.00,135.00,false],
        ['Ice Creams','Oreo Ice Cream','Oreo cookie crumble ice cream',null,92.00,138.00,false],
        ['Ice Creams','Butterscotch Ice Cream','Creamy butterscotch with praline bits',null,88.00,130.00,false],
        ['Ice Creams','Kesar Pista Ice Cream','Saffron & pistachio Indian royal ice cream',null,95.00,145.00,false],
        ['Ice Creams','Tender Coconut Ice Cream','Fresh tender coconut flavor',null,88.00,132.00,false],
        ['Ice Creams','Blueberry Cheesecake Ice Cream','Cream cheese with blueberry swirl',null,98.00,148.00,false],
        // BROWNIES
        ['Brownies','Classic Fudge Brownie','Rich fudgy chocolate brownie slice',65.00,null,null,false],
        ['Brownies','Walnut Brownie','Fudge brownie with crunchy walnuts',75.00,null,null,false],
        ['Brownies','Oreo Brownie','Chocolate brownie with oreo chunks',80.00,null,null,false],
        ['Brownies','Caramel Brownie','Fudge brownie with salted caramel drizzle',78.00,null,null,false],
        ['Brownies','Eggless Brownie','100% Eggless chocolate brownie',72.00,null,null,true],
        ['Brownies','Nutella Swirl Brownie','Fudge brownie with Nutella marble',85.00,null,null,false],
        ['Brownies','Peanut Butter Brownie','Chocolate brownie with peanut butter swirl',82.00,null,null,false],
        ['Brownies','Rocky Road Brownie','Brownie with marshmallows, nuts & chocolate chips',88.00,null,null,false],
        // MILKSHAKES
        ['Milkshakes','Chocolate Milkshake 300ml','Thick chocolate milkshake with ice cream',120.00,null,null,false],
        ['Milkshakes','Vanilla Milkshake 300ml','Classic creamy vanilla milkshake',110.00,null,null,false],
        ['Milkshakes','Strawberry Milkshake 300ml','Fresh strawberry milkshake',125.00,null,null,false],
        ['Milkshakes','Mango Milkshake 300ml','Alphonso mango milkshake',135.00,null,null,false],
        ['Milkshakes','Oreo Milkshake 300ml','Oreo cookie crumble milkshake',145.00,null,null,false],
        ['Milkshakes','Butterscotch Milkshake 300ml','Creamy butterscotch thick shake',130.00,null,null,false],
        ['Milkshakes','Kesar Badam Milkshake 300ml','Saffron almond rich milkshake',150.00,null,null,false],
        ['Milkshakes','Chocolate Banana Milkshake 300ml','Chocolate with banana blend',128.00,null,null,false],
        ['Milkshakes','Cold Coffee Shake 300ml','Espresso blended with ice cream',140.00,null,null,false],
        // MOJITOS
        ['Mojitos','Classic Mint Mojito 300ml','Fresh mint, lime, soda refreshment',110.00,null,null,false],
        ['Mojitos','Strawberry Mojito 300ml','Strawberry infused mojito',125.00,null,null,false],
        ['Mojitos','Mango Mojito 300ml','Mango with mint & lime',130.00,null,null,false],
        ['Mojitos','Blueberry Mojito 300ml','Blueberry & mint refreshment',135.00,null,null,false],
        ['Mojitos','Lemon Mojito 300ml','Tangy lemon mint mojito',115.00,null,null,false],
        ['Mojitos','Virgin Peach Mojito 300ml','Juicy peach with sparkling soda',128.00,null,null,false],
        ['Mojitos','Watermelon Mojito 300ml','Fresh watermelon with mint',120.00,null,null,false],
        ['Mojitos','Kiwi Mojito 300ml','Tropical kiwi with lime & soda',132.00,null,null,false],
        // SNACKS
        ['Snacks','Cheese Balls 8pcs','Crispy cheese stuffed balls',180.00,null,null,false],
        ['Snacks','Paneer Tikka 8pcs','Smoked marinated paneer cubes',250.00,null,null,false],
        ['Snacks','French Fries Regular','Crispy golden french fries',150.00,null,null,false],
        ['Snacks','Garlic Bread 6pcs','Cheesy garlic bread sticks',160.00,null,null,false],
        ['Snacks','Veg Manchurian 10pcs','Crispy veg balls in manchurian sauce',220.00,null,null,false],
        ['Snacks','Sandwich Veg Grill','Grilled vegetable sandwich',140.00,null,null,false],
        ['Snacks','Onion Rings 12pcs','Crispy golden battered onion rings',170.00,null,null,false],
        ['Snacks','Nachos with Dips','Corn tortilla chips with salsa & cheese dip',200.00,null,null,false],
        ['Snacks','Peri Peri Fries','Spicy peri peri seasoned fries',165.00,null,null,false],
        ['Snacks','Corn Cheese Toast 4pcs','Cheesy corn toasted bread slices',175.00,null,null,false],
    ];
    $id = 1;
    foreach($demoData as $d){
        $products[] = [
            'id' => $id++, 'category' => $d[0], 'name' => $d[1], 'description' => $d[2],
            'price' => $d[3] ?? $d[4], 'price_small' => $d[4], 'price_medium' => $d[5],
            'price_large' => null, 'is_vegetarian' => 1, 'is_eggless' => $d[6] ? 1 : 0,
        ];
    }
}

// ─── AJAX: SAVE ORDER TO DATABASE ───
// This was missing before — placeOrder() in JS never called the server,
// so nothing ever reached the orders / order_items tables.
if (isset($_GET['action']) && $_GET['action'] === 'place_order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (!isset($pdo)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database is not connected.']);
        exit;
    }

    $data  = json_decode(file_get_contents('php://input'), true);
    $items = $data['items'] ?? [];

    if (!$items) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Cart is empty.']);
        exit;
    }

    $name    = trim($data['customer_name']    ?? '');
    $phone   = trim($data['customer_phone']    ?? '');
    $address = trim($data['customer_address']  ?? '');

    $total = 0;
    foreach ($items as $it) $total += (float)$it['price'] * (int)$it['qty'];
    $tax   = round($total * 0.05, 2);
    $grand = $total + $tax;

    try {
        $pdo->beginTransaction();

        $ins = $pdo->prepare(
            "INSERT INTO orders (customer_name, customer_phone, customer_address, total_amount, payment_status, order_status)
             VALUES (?, ?, ?, ?, 'Paid', 'Preparing')"
        );
        $ins->execute([$name ?: null, $phone ?: null, $address ?: null, $grand]);
        $orderId = $pdo->lastInsertId();

        $itemStmt = $pdo->prepare(
            "INSERT INTO order_items (order_id, product_id, product_name, quantity, selected_size, price, subtotal)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($items as $it) {
            $itemStmt->execute([
                $orderId,
                (int)$it['id'],
                $it['name'],
                (int)$it['qty'],
                $it['size'] ?? null,
                (float)$it['price'],
                (float)$it['price'] * (int)$it['qty'],
            ]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'order_id' => $orderId]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ─── VIEW: LIST SAVED ORDERS (simple read-only admin view) ───
// Visit index.php?view=orders to confirm rows are actually being saved.
if (isset($_GET['view']) && $_GET['view'] === 'orders') {
    header('Content-Type: text/html; charset=utf-8');

    if (!isset($pdo)) {
        die('Database is not connected — orders cannot be displayed.');
    }

    $orders   = $pdo->query("SELECT * FROM orders ORDER BY created_at DESC")->fetchAll();
    $itemStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");

    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Saved Orders</title><style>
        body{font-family:sans-serif;padding:24px;background:#f2f2f7;color:#1c1c1e}
        h2{margin-bottom:18px}
        table{border-collapse:collapse;width:100%;background:#fff;margin-bottom:26px;border-radius:8px;overflow:hidden}
        th,td{border:1px solid #e8e8e8;padding:8px 10px;font-size:13px;text-align:left}
        th{background:#1c1c1e;color:#fff}
        .ord-card{background:#fff;border-radius:10px;padding:14px 16px;margin-bottom:18px;border:1px solid #e8e8e8}
        .ord-hd{font-weight:700;margin-bottom:10px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px}
        .ord-status{font-size:.75rem;font-weight:700;padding:2px 9px;border-radius:20px;background:#fff0f1;color:#e23744}
    </style></head><body><h2>Saved Orders (' . count($orders) . ')</h2>';

    if (!$orders) {
        echo '<p>No orders saved yet — place one from the menu page first.</p>';
    }

    foreach ($orders as $o) {
        echo '<div class="ord-card"><div class="ord-hd">';
        echo '<span>Order #' . (int)$o['id'] . ' — ' . htmlspecialchars($o['customer_name'] ?: 'Guest') . '</span>';
        echo '<span class="ord-status">' . htmlspecialchars($o['order_status']) . '</span>';
        echo '</div>';
        echo '<div style="font-size:.8rem;color:#6b6b6b;margin-bottom:10px">' .
             htmlspecialchars($o['customer_phone'] ?: '') . ' · ' .
             htmlspecialchars($o['customer_address'] ?: '') . ' · ' .
             htmlspecialchars($o['created_at']) . ' · Total ₹' . number_format($o['total_amount'], 2) .
             '</div>';

        $itemStmt->execute([$o['id']]);
        $rows = $itemStmt->fetchAll();
        echo '<table><tr><th>Item</th><th>Size</th><th>Qty</th><th>Price</th><th>Subtotal</th></tr>';
        foreach ($rows as $it) {
            echo '<tr><td>' . htmlspecialchars($it['product_name']) . '</td>' .
                 '<td>' . htmlspecialchars($it['selected_size'] ?? '') . '</td>' .
                 '<td>' . (int)$it['quantity'] . '</td>' .
                 '<td>₹' . number_format($it['price'], 2) . '</td>' .
                 '<td>₹' . number_format($it['subtotal'], 2) . '</td></tr>';
        }
        echo '</table></div>';
    }

    echo '</body></html>';
    exit;
}

// ─── IMAGE MAP ───
$imageMap = [
    // CAKES
    'Chocolate Fudge Cake 1kg'        => 'https://images.unsplash.com/photo-1578985545062-69928b1d9587?w=400&q=80',
    'Red Velvet Cake 1kg'             => 'https://images.unsplash.com/photo-1616541823729-00fe0aacd32c?w=400&q=80',
    'Black Forest Cake 1kg'           => 'https://images.unsplash.com/photo-1565958011703-44f9829ba187?w=400&q=80',
    'Vanilla Sponge Cake 1kg'         => 'https://images.unsplash.com/photo-1614707267537-b85aaf00c4b7?w=400&q=80',
    'Tiramisu Cake 1kg'               => 'https://images.unsplash.com/photo-1571877227200-a0d98ea607e9?w=400&q=80',
    'Strawberry Cake 1kg'             => 'https://images.unsplash.com/photo-1488477181946-6428a0291777?w=400&q=80',
    'Butterscotch Cake 1kg'           => 'https://images.unsplash.com/photo-1464349095431-e9a21285b5f3?w=400&q=80',
    'Eggless Chocolate Cake 1kg'      => 'https://images.unsplash.com/photo-1602351447937-745cb720612f?w=400&q=80',
    'Pineapple Cake 1kg'              => 'https://images.unsplash.com/photo-1562440499-64c9a111f713?w=400&q=80',
    'Mango Delight Cake 1kg'          => 'https://images.unsplash.com/photo-1571115177098-24ec42ed204d?w=400&q=80',
    'Lemon Drizzle Cake 1kg'          => 'https://images.unsplash.com/photo-1519869325930-281384150729?w=400&q=80',
    'Carrot Walnut Cake 1kg'          => 'https://images.unsplash.com/photo-1621303837174-89787a7d4729?w=400&q=80',
    'Death By Chocolate 1kg'          => 'https://images.unsplash.com/photo-1606313564200-e75d5e30476c?w=400&q=80',
    'Coffee Walnut Cake 1kg'          => 'https://images.unsplash.com/photo-1560180474-e8563fd75bab?w=400&q=80',
    'Rose Falooda Cake 1kg'           => 'https://images.unsplash.com/photo-1563729784474-d77dbb933a9e?w=400&q=80',
    // CUPCAKES
    'Red Velvet Cupcakes 6pcs'        => 'https://images.unsplash.com/photo-1614707267537-b85aaf00c4b7?w=400&q=80',
    'Chocolate Cupcakes 6pcs'         => 'https://images.unsplash.com/photo-1587668178277-295251f900ce?w=400&q=80',
    'Vanilla Cupcakes 6pcs'           => 'https://images.unsplash.com/photo-1570145820259-b5571eff5e7b?w=400&q=80',
    'Oreo Cupcakes 6pcs'              => 'https://images.unsplash.com/photo-1599785209707-a456fc1337bb?w=400&q=80',
    'Lemon Cupcakes 6pcs'             => 'https://images.unsplash.com/photo-1576618148400-f54bed99fcfd?w=400&q=80',
    'Strawberry Cupcakes 6pcs'        => 'https://images.unsplash.com/photo-1557979619-445218c326a8?w=400&q=80',
    'Caramel Cupcakes 6pcs'           => 'https://images.unsplash.com/photo-1519869325930-281384150729?w=400&q=80',
    'Blueberry Cupcakes 6pcs'         => 'https://images.unsplash.com/photo-1607958996333-41aef7caefaa?w=400&q=80',
    'Nutella Cupcakes 6pcs'           => 'https://images.unsplash.com/photo-1486427944299-d1955d23e34d?w=400&q=80',
    'Eggless Chocolate Cupcakes 6pcs' => 'https://images.unsplash.com/photo-1607958996333-41aef7caefaa?w=400&q=80',
    // PASTRIES
    'Butter Croissants 4pcs'          => 'https://images.unsplash.com/photo-1555507036-ab1f4038808a?w=400&q=80',
    'Almond Croissants 2pcs'          => 'https://images.unsplash.com/photo-1549903072-7e6e0bedb7fb?w=400&q=80',
    'Pain au Chocolat 4pcs'           => 'https://images.unsplash.com/photo-1612886903028-bd5c2d8e00a0?w=400&q=80',
    'Chocolate Eclairs 4pcs'          => 'https://images.unsplash.com/photo-1548365328-8c6db3220e4c?w=400&q=80',
    'Fruit Tart 1pc'                  => 'https://images.unsplash.com/photo-1519915028121-7d3463d20b13?w=400&q=80',
    'Creme Brulee Tart 2pcs'          => 'https://images.unsplash.com/photo-1470124182917-cc6e71b22ecc?w=400&q=80',
    'Apple Danish 4pcs'               => 'https://images.unsplash.com/photo-1509365465985-25d11c17e812?w=400&q=80',
    'Cherry Danish 4pcs'              => 'https://images.unsplash.com/photo-1504113888839-1c8eb50233d3?w=400&q=80',
    'Palmier 6pcs'                    => 'https://images.unsplash.com/photo-1586444248902-2f64eddc13df?w=400&q=80',
    'Cannoli 2pcs'                    => 'https://images.unsplash.com/photo-1551024506-0bccd828d307?w=400&q=80',
    // COOKIES
    'Chocolate Chip Cookies 12pcs'    => 'https://images.unsplash.com/photo-1499636136210-6f4ee915583e?w=400&q=80',
    'Peanut Butter Cookies 12pcs'     => 'https://images.unsplash.com/photo-1590080877861-53c4c9e89de8?w=400&q=80',
    'Double Chocolate Cookies 12pcs'  => 'https://images.unsplash.com/photo-1607920592519-bab2d204a5e7?w=400&q=80',
    'Gingerbread Cookies 12pcs'       => 'https://images.unsplash.com/photo-1481391243133-f96216dcb5d2?w=400&q=80',
    'Oatmeal Raisin Cookies 12pcs'    => 'https://images.unsplash.com/photo-1558558538-b0e2a0c7b879?w=400&q=80',
    'Almond Biscotti 8pcs'            => 'https://images.unsplash.com/photo-1568051243858-533a607809a5?w=400&q=80',
    'Snickerdoodle Cookies 12pcs'     => 'https://images.unsplash.com/photo-1574085733277-851d9d856a3a?w=400&q=80',
    'White Chocolate Macadamia 12pcs' => 'https://images.unsplash.com/photo-1499636136210-6f4ee915583e?w=400&q=80',
    // MUFFINS
    'Blueberry Muffins 2pcs'          => 'https://images.unsplash.com/photo-1607958996333-41aef7caefaa?w=400&q=80',
    'Banana Chocolate Muffins 2pcs'   => 'https://images.unsplash.com/photo-1600326145552-327f74df9674?w=400&q=80',
    'Chocolate Chip Muffins 2pcs'     => 'https://images.unsplash.com/photo-1506224772180-d75b3efbe9be?w=400&q=80',
    'Lemon Poppy Seed Muffins 2pcs'   => 'https://images.unsplash.com/photo-1558303489-d47d96b69e77?w=400&q=80',
    'Carrot Raisin Muffins 2pcs'      => 'https://images.unsplash.com/photo-1600326145454-c5a41b2aa4a2?w=400&q=80',
    'Bran Raisin Muffins 2pcs'        => 'https://images.unsplash.com/photo-1600326145454-c5a41b2aa4a2?w=400&q=80',
    // ICE CREAMS
    'Vanilla Ice Cream'               => 'https://images.unsplash.com/photo-1570197788417-0e82375c9371?w=400&q=80',
    'Chocolate Ice Cream'             => 'https://images.unsplash.com/photo-1563805042-7684c019e1cb?w=400&q=80',
    'Strawberry Ice Cream'            => 'https://images.unsplash.com/photo-1497034825429-c343d7c6a68f?w=400&q=80',
    'Mango Ice Cream'                 => 'https://images.unsplash.com/photo-1560008581-09826d1de69e?w=400&q=80',
    'Oreo Ice Cream'                  => 'https://images.unsplash.com/photo-1580915411954-282cb1b0d780?w=400&q=80',
    'Butterscotch Ice Cream'          => 'https://images.unsplash.com/photo-1551024506-0bccd828d307?w=400&q=80',
    'Kesar Pista Ice Cream'           => 'https://images.unsplash.com/photo-1529030133173-6430b3f7a65f?w=400&q=80',
    'Tender Coconut Ice Cream'        => 'https://images.unsplash.com/photo-1535141192574-5d4897c12636?w=400&q=80',
    'Blueberry Cheesecake Ice Cream'  => 'https://images.unsplash.com/photo-1516559828984-fb3b99548b21?w=400&q=80',
    // BROWNIES
    'Classic Fudge Brownie'           => 'https://images.unsplash.com/photo-1606313564200-e75d5e30476c?w=400&q=80',
    'Walnut Brownie'                  => 'https://images.unsplash.com/photo-1589375203949-f0a6e5048fb2?w=400&q=80',
    'Oreo Brownie'                    => 'https://images.unsplash.com/photo-1564355808539-22fda35bed7e?w=400&q=80',
    'Caramel Brownie'                 => 'https://images.unsplash.com/photo-1610611424854-5e01e95f5e84?w=400&q=80',
    'Eggless Brownie'                 => 'https://images.unsplash.com/photo-1515037893149-de7f840978e2?w=400&q=80',
    'Nutella Swirl Brownie'           => 'https://images.unsplash.com/photo-1558961363-fa8fdf82db35?w=400&q=80',
    'Peanut Butter Brownie'           => 'https://images.unsplash.com/photo-1515037893149-de7f840978e2?w=400&q=80',
    'Rocky Road Brownie'              => 'https://images.unsplash.com/photo-1589375203949-f0a6e5048fb2?w=400&q=80',
    // MILKSHAKES
    'Chocolate Milkshake 300ml'       => 'https://images.unsplash.com/photo-1572490122747-3968b75cc699?w=400&q=80',
    'Vanilla Milkshake 300ml'         => 'https://images.unsplash.com/photo-1570197788417-0e82375c9371?w=400&q=80',
    'Strawberry Milkshake 300ml'      => 'https://images.unsplash.com/photo-1546039907-7fa05f864c02?w=400&q=80',
    'Mango Milkshake 300ml'           => 'https://images.unsplash.com/photo-1638176066666-ffb2f013c7dd?w=400&q=80',
    'Oreo Milkshake 300ml'            => 'https://images.unsplash.com/photo-1541658016709-82535e94bc69?w=400&q=80',
    'Butterscotch Milkshake 300ml'    => 'https://images.unsplash.com/photo-1579954115545-a95591f28bfc?w=400&q=80',
    'Kesar Badam Milkshake 300ml'     => 'https://images.unsplash.com/photo-1546241072-48010ad2862c?w=400&q=80',
    'Chocolate Banana Milkshake 300ml'=> 'https://images.unsplash.com/photo-1623065422902-30a2d299bbe4?w=400&q=80',
    'Cold Coffee Shake 300ml'         => 'https://images.unsplash.com/photo-1461023058943-07fcbe16d735?w=400&q=80',
    // MOJITOS
    'Classic Mint Mojito 300ml'       => 'https://images.unsplash.com/photo-1556679343-c7306c1976bc?w=400&q=80',
    'Strawberry Mojito 300ml'         => 'https://images.unsplash.com/photo-1544145945-f90425340c7e?w=400&q=80',
    'Mango Mojito 300ml'              => 'https://images.unsplash.com/photo-1613478223719-2ab802602423?w=400&q=80',
    'Blueberry Mojito 300ml'          => 'https://images.unsplash.com/photo-1615478503562-ec2d8aa0e24e?w=400&q=80',
    'Lemon Mojito 300ml'              => 'https://images.unsplash.com/photo-1621263764928-df1444c5e859?w=400&q=80',
    'Virgin Peach Mojito 300ml'       => 'https://images.unsplash.com/photo-1513558161293-cdaf765ed2fd?w=400&q=80',
    'Watermelon Mojito 300ml'         => 'https://images.unsplash.com/photo-1587840171670-8b850147754e?w=400&q=80',
    'Kiwi Mojito 300ml'               => 'https://images.unsplash.com/photo-1560526860-1f0e56046c85?w=400&q=80',
    // SNACKS
    'Cheese Balls 8pcs'               => 'https://images.unsplash.com/photo-1541592106381-b31e9677c0e5?w=400&q=80',
    'Paneer Tikka 8pcs'               => 'https://images.unsplash.com/photo-1567188040759-fb8a883dc6d6?w=400&q=80',
    'French Fries Regular'            => 'https://images.unsplash.com/photo-1573080496219-bb080dd4f877?w=400&q=80',
    'Garlic Bread 6pcs'               => 'https://images.unsplash.com/photo-1619535860434-cf9b902f7791?w=400&q=80',
    'Veg Manchurian 10pcs'            => 'https://images.unsplash.com/photo-1585937421612-70a008356fbe?w=400&q=80',
    'Sandwich Veg Grill'              => 'https://images.unsplash.com/photo-1528736235302-52922df5c122?w=400&q=80',
    'Onion Rings 12pcs'               => 'https://images.unsplash.com/photo-1639024471283-03518883512d?w=400&q=80',
    'Nachos with Dips'                => 'https://images.unsplash.com/photo-1513456852971-30c0b8199d4d?w=400&q=80',
    'Peri Peri Fries'                 => 'https://images.unsplash.com/photo-1576107232684-1279f2e0bedb?w=400&q=80',
    'Corn Cheese Toast 4pcs'          => 'https://images.unsplash.com/photo-1484723091739-30a097e8f929?w=400&q=80',
];

$fallback = [
    'Cakes'      => 'https://images.unsplash.com/photo-1578985545062-69928b1d9587?w=400&q=80',
    'Cupcakes'   => 'https://images.unsplash.com/photo-1587668178277-295251f900ce?w=400&q=80',
    'Pastries'   => 'https://images.unsplash.com/photo-1555507036-ab1f4038808a?w=400&q=80',
    'Cookies'    => 'https://images.unsplash.com/photo-1499636136210-6f4ee915583e?w=400&q=80',
    'Muffins'    => 'https://images.unsplash.com/photo-1607958996333-41aef7caefaa?w=400&q=80',
    'Ice Creams' => 'https://images.unsplash.com/photo-1570197788417-0e82375c9371?w=400&q=80',
    'Snacks'     => 'https://images.unsplash.com/photo-1573080496219-bb080dd4f877?w=400&q=80',
    'Brownies'   => 'https://images.unsplash.com/photo-1606313564200-e75d5e30476c?w=400&q=80',
    'Milkshakes' => 'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?w=400&q=80',
    'Mojitos'    => 'https://images.unsplash.com/photo-1571950006419-6a40daae7d35?w=400&q=80',
];

$icons = ['Cakes'=>'🍰','Cupcakes'=>'🧁','Pastries'=>'🥐','Cookies'=>'🍪','Muffins'=>'🫐',
          'Ice Creams'=>'🍨','Snacks'=>'🍟','Brownies'=>'🍫','Milkshakes'=>'🥤','Mojitos'=>'🍹'];

// Group by category
$grouped = [];
foreach($products as $p) $grouped[$p['category']][] = $p;

function getImg($name,$cat,$imap,$fb){ return $imap[$name] ?? ($fb[$cat] ?? ''); }

// Build JS products array
$jsProds = [];
foreach($products as $p){
    $base = !empty($p['price_small']) ? (float)$p['price_small'] : (float)$p['price'];
    $jsProds[] = [
        'id'    => (int)$p['id'],
        'name'  => $p['name'],
        'desc'  => $p['description'],
        'price' => $base,
        'priceM'=> !empty($p['price_medium']) ? (float)$p['price_medium'] : null,
        'priceL'=> !empty($p['price_large'])  ? (float)$p['price_large']  : null,
        'cat'   => $p['category'],
        'isVeg' => (bool)$p['is_vegetarian'],
        'isEgg' => (bool)$p['is_eggless'],
        'img'   => getImg($p['name'],$p['category'],$imageMap,$fallback),
        'fb'    => $fallback[$p['category']] ?? '',
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sweet Delights Bakery</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ─── RESET & BASE ─── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth;font-size:15px}
body{font-family:'Plus Jakarta Sans',sans-serif;background:#f2f2f7;color:#1c1c1e;overflow-x:hidden}
button{font-family:inherit;cursor:pointer}
img{display:block}

/* ─── VARIABLES ─── */
:root{
  --brand:#e23744;
  --brand-d:#c01f2c;
  --brand-bg:#fff0f1;
  --green:#1ba672;
  --amber:#f5a623;
  --ink:#1c1c1e;
  --sub:#6b6b6b;
  --border:#e8e8e8;
  --surface:#ffffff;
  --bg:#f2f2f7;
  --nav:64px;
  --sidebar:260px;
  --ease:cubic-bezier(.25,.46,.45,.94);
}

/* ─── TOP HEADER ─── */
.header{
  position:sticky;top:0;z-index:800;
  background:var(--surface);
  border-bottom:1px solid var(--border);
  height:var(--nav);
  box-shadow:0 1px 8px rgba(0,0,0,.07);
}
.header-inner{
  max-width:1280px;margin:0 auto;height:100%;
  padding:0 20px;display:flex;align-items:center;gap:16px;
}
.hbrand{
  font-size:1.2rem;font-weight:800;color:var(--brand);
  text-decoration:none;white-space:nowrap;letter-spacing:-.3px;
  display:flex;align-items:center;gap:6px;flex-shrink:0;
}
.hbrand-sub{font-size:.65rem;font-weight:500;color:var(--sub);letter-spacing:.3px;margin-top:1px;display:block}

/* search bar */
.hsearch{
  flex:1;max-width:520px;position:relative;
}
.hsearch input{
  width:100%;padding:10px 16px 10px 40px;
  border:2px solid var(--border);border-radius:10px;
  font-family:inherit;font-size:.88rem;color:var(--ink);
  background:#f7f7f7;outline:none;transition:border-color .2s,background .2s;
}
.hsearch input:focus{border-color:var(--brand);background:#fff}
.hsearch svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--sub);pointer-events:none}

/* header right */
.hright{margin-left:auto;display:flex;align-items:center;gap:10px}
.hbtn{
  display:flex;align-items:center;gap:6px;
  padding:9px 18px;border-radius:9px;
  font-size:.84rem;font-weight:600;border:none;
  transition:background .18s,transform .18s;
}
.hbtn-outline{background:#fff;border:1.5px solid var(--border);color:var(--ink)}
.hbtn-outline:hover{border-color:var(--brand);color:var(--brand)}
.hbtn-cart{background:var(--brand);color:#fff;position:relative}
.hbtn-cart:hover{background:var(--brand-d);transform:translateY(-1px)}
.cart-count{
  background:#fff;color:var(--brand);
  border-radius:50%;min-width:18px;height:18px;
  font-size:.68rem;font-weight:700;
  display:flex;align-items:center;justify-content:center;padding:0 3px;
}

/* ─── RESTAURANT BANNER ─── */
.banner{
  background:var(--surface);
  border-bottom:1px solid var(--border);
}
.banner-inner{
  max-width:1280px;margin:0 auto;padding:22px 20px 18px;
  display:flex;align-items:center;gap:18px;
}
.banner-img{
  width:88px;height:88px;border-radius:14px;
  object-fit:cover;flex-shrink:0;
  border:2px solid var(--border);
}
.banner-info{flex:1}
.banner-name{font-size:1.35rem;font-weight:800;letter-spacing:-.3px;margin-bottom:4px}
.banner-tags{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px}
.btag{
  font-size:.72rem;font-weight:600;padding:3px 9px;border-radius:20px;
  background:#f0f0f0;color:var(--sub);
}
.banner-meta{display:flex;align-items:center;gap:16px;font-size:.82rem;color:var(--sub)}
.bm-item{display:flex;align-items:center;gap:4px}
.rating-badge{
  background:var(--green);color:#fff;border-radius:6px;
  padding:3px 7px;font-size:.78rem;font-weight:700;
  display:flex;align-items:center;gap:3px;
}
.offer-strip{
  background:linear-gradient(90deg,#ff6b35,var(--brand));
  color:#fff;font-size:.75rem;font-weight:700;
  padding:7px 20px;letter-spacing:.3px;
  display:flex;align-items:center;gap:8px;justify-content:center;
}

/* ─── MAIN LAYOUT ─── */
.page-body{
  max-width:1280px;margin:0 auto;
  display:grid;
  grid-template-columns:1fr;
  gap:0;
  align-items:start;
  padding:0 20px 100px;
}

/* ─── LEFT: MENU CONTENT ─── */
.menu-content{padding-right:20px;padding-top:20px}

/* CATEGORY SECTION */
.cat-section{margin-bottom:8px}

/* CATEGORY ACCORDION HEADER */
.cat-accord{
  display:flex;align-items:center;justify-content:space-between;
  padding:16px 0 14px;
  border-bottom:1px solid var(--border);
  cursor:pointer;user-select:none;
}
.cat-accord-left{display:flex;align-items:center;gap:10px}
.cat-accord-icon{font-size:1.25rem}
.cat-accord-name{font-size:1rem;font-weight:700;color:var(--ink)}
.cat-accord-count{
  font-size:.72rem;font-weight:600;color:var(--sub);
  background:#f0f0f0;padding:2px 8px;border-radius:20px;margin-left:6px;
}
.cat-accord-arrow{
  width:28px;height:28px;border-radius:50%;
  background:#f5f5f5;border:none;
  display:flex;align-items:center;justify-content:center;
  font-size:.7rem;color:var(--sub);
  transition:transform .3s var(--ease),background .2s;flex-shrink:0;
}
.cat-section.open .cat-accord-arrow{transform:rotate(180deg);background:var(--brand-bg);color:var(--brand)}
.cat-accord:hover .cat-accord-name{color:var(--brand)}

/* ITEMS LIST (accordion body) */
.cat-items{
  overflow:hidden;
  max-height:0;
  transition:max-height .4s var(--ease);
}
.cat-section.open .cat-items{max-height:9999px}

/* ─── FOOD ITEM ROW ─── */
.food-item{
  display:flex;align-items:flex-start;gap:14px;
  padding:18px 0;
  border-bottom:1px solid #f5f5f5;
  position:relative;
}
.food-item:last-child{border-bottom:none}

/* left: text info */
.fi-info{flex:1;min-width:0}
.fi-badges{display:flex;align-items:center;gap:6px;margin-bottom:5px}
.veg-dot{
  width:14px;height:14px;border-radius:3px;flex-shrink:0;
  border:1.5px solid;display:flex;align-items:center;justify-content:center;
}
.veg-dot.v{border-color:var(--green)}
.veg-dot.v::before{content:'';width:6px;height:6px;border-radius:50%;background:var(--green)}
.veg-dot.e{border-color:var(--amber)}
.veg-dot.e::before{content:'';width:6px;height:6px;border-radius:50%;background:var(--amber)}
.fi-eggless{font-size:.65rem;font-weight:700;color:var(--amber);background:#fff8e8;border:1px solid #f5d98a;padding:1px 6px;border-radius:4px}
.fi-bestseller{font-size:.65rem;font-weight:700;color:#e67e22;background:#fff4e5;border:1px solid #fddbb4;padding:1px 6px;border-radius:4px}
.fi-name{font-size:.95rem;font-weight:700;margin-bottom:4px;line-height:1.35}
.fi-price{font-size:.95rem;font-weight:700;color:var(--ink);margin-bottom:6px}
.fi-desc{font-size:.78rem;color:var(--sub);line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}

/* size selector */
.fi-sizes{
  display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;
}
.sz-btn{
  padding:4px 10px;border-radius:6px;font-size:.74rem;font-weight:600;
  border:1.5px solid var(--border);background:#fff;color:var(--sub);
  transition:.18s;
}
.sz-btn.active,.sz-btn:hover{border-color:var(--brand);color:var(--brand);background:var(--brand-bg)}

/* right: image + add button */
.fi-right{flex-shrink:0;position:relative;width:118px}
.fi-img{
  width:118px;height:88px;border-radius:12px;
  object-fit:cover;background:#f5f5f5;
}
.fi-img-placeholder{
  width:118px;height:88px;border-radius:12px;
  background:linear-gradient(135deg,#f8e8ea,#fdf0f0);
  display:flex;align-items:center;justify-content:center;
  font-size:2rem;
}

/* ADD button — floats below image */
.fi-add-wrap{
  position:absolute;bottom:-12px;left:50%;
  transform:translateX(-50%);
  display:flex;align-items:center;
}
.fi-add-btn{
  background:#fff;border:1.5px solid var(--brand);
  color:var(--brand);border-radius:9px;
  padding:5px 18px;font-size:.85rem;font-weight:700;
  box-shadow:0 4px 12px rgba(226,55,68,.18);
  transition:background .18s,color .18s,transform .2s;
  white-space:nowrap;
}
.fi-add-btn:hover{background:var(--brand);color:#fff;transform:scale(1.05)}

/* qty control */
.fi-qty{
  display:none;
  background:var(--brand);border-radius:9px;
  align-items:center;gap:0;
  box-shadow:0 4px 12px rgba(226,55,68,.25);
  overflow:hidden;
}
.fi-qty.show{display:flex}
.qbtn{
  width:32px;height:32px;background:none;border:none;
  color:#fff;font-size:1rem;font-weight:700;
  display:flex;align-items:center;justify-content:center;
}
.qbtn:hover{background:rgba(255,255,255,.15)}
.qnum{
  min-width:22px;text-align:center;
  font-size:.85rem;font-weight:700;color:#fff;
}

/* ─── FLOATING MENU BUTTON (FAB) ─── */
.menu-fab{
  position:fixed;bottom:32px;right:28px;z-index:850;
  background:var(--ink);color:#fff;
  border:none;border-radius:50px;
  padding:12px 20px;
  display:flex;align-items:center;gap:8px;
  font-size:.88rem;font-weight:700;
  box-shadow:0 6px 24px rgba(0,0,0,.22);
  transition:background .2s,transform .2s;
  cursor:pointer;
}
.menu-fab:hover{background:var(--brand);transform:translateY(-2px)}
.menu-fab-icon{font-size:1rem;line-height:1}

/* ─── MENU DRAWER OVERLAY ─── */
.menu-overlay{
  position:fixed;inset:0;background:rgba(0,0,0,.45);
  backdrop-filter:blur(3px);z-index:900;
  display:none;opacity:0;
  transition:opacity .3s;
}
.menu-overlay.open{display:block;opacity:1}

/* ─── MENU DRAWER PANEL ─── */
.menu-drawer{
  position:fixed;right:-320px;top:0;width:300px;height:100vh;
  background:var(--surface);z-index:901;
  transition:right .38s cubic-bezier(.25,.46,.45,.94);
  display:flex;flex-direction:column;
  box-shadow:-4px 0 40px rgba(0,0,0,.14);
}
.menu-drawer.open{right:0}
.md-head{
  padding:18px 18px 14px;
  border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;flex-shrink:0;
}
.md-title{font-size:.95rem;font-weight:800;letter-spacing:-.2px}
.md-close{
  width:30px;height:30px;border-radius:50%;border:1.5px solid var(--border);
  background:#fff;display:flex;align-items:center;justify-content:center;
  font-size:.78rem;color:var(--sub);cursor:pointer;transition:.18s;
}
.md-close:hover{background:var(--brand);color:#fff;border-color:var(--brand)}
.md-list{overflow-y:auto;flex:1}
.md-list::-webkit-scrollbar{width:3px}
.md-list::-webkit-scrollbar-thumb{background:#ddd;border-radius:10px}
.md-item{
  display:flex;align-items:center;gap:12px;
  padding:11px 16px;cursor:pointer;
  border-left:3px solid transparent;
  transition:all .16s;
}
.md-item:hover{background:#fafafa}
.md-item.active{border-left-color:var(--brand);background:var(--brand-bg)}
.md-item.active .md-item-name{color:var(--brand);font-weight:700}
.md-img{
  width:42px;height:42px;border-radius:9px;
  object-fit:cover;flex-shrink:0;background:#f5f5f5;
}
.md-item-name{font-size:.84rem;font-weight:600;color:var(--ink);line-height:1.3}
.md-item-count{font-size:.7rem;color:var(--sub);margin-top:1px}

.sidebar{ display:none; }

/* ─── CART DRAWER ─── */
.cart-overlay{
  position:fixed;inset:0;background:rgba(0,0,0,.45);
  backdrop-filter:blur(3px);z-index:1000;
  display:none;
}
.cart-panel{
  position:fixed;right:-480px;top:0;width:440px;height:100vh;
  background:var(--surface);z-index:1001;
  transition:right .4s var(--ease);
  display:flex;flex-direction:column;
  box-shadow:-4px 0 40px rgba(0,0,0,.12);
}
.cart-panel.open{right:0}
.cart-head{
  padding:20px 22px 16px;
  border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;flex-shrink:0;
}
.cart-head-title{font-size:1rem;font-weight:700}
.cart-head-sub{font-size:.76rem;color:var(--sub);margin-top:2px}
.cart-close{
  width:32px;height:32px;border-radius:50%;
  border:1.5px solid var(--border);background:#fff;
  display:flex;align-items:center;justify-content:center;
  font-size:.8rem;color:var(--sub);transition:.18s;
}
.cart-close:hover{background:var(--brand);color:#fff;border-color:var(--brand)}
.cart-body{flex:1;overflow-y:auto;padding:0 22px}
.cart-body::-webkit-scrollbar{width:3px}
.cart-body::-webkit-scrollbar-thumb{background:#ddd;border-radius:10px}
.cart-empty{text-align:center;padding:60px 20px;color:#bbb}
.cart-empty-icon{font-size:3rem;margin-bottom:12px}
.ci{
  display:flex;align-items:center;gap:12px;
  padding:14px 0;border-bottom:1px dashed #f0f0f0;
}
.ci-img{width:44px;height:44px;border-radius:8px;object-fit:cover;flex-shrink:0;background:#f5f5f5}
.ci-info{flex:1;min-width:0}
.ci-name{font-size:.84rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ci-price{font-size:.78rem;color:var(--sub);margin-top:2px}
.ci-qty{
  display:flex;align-items:center;gap:0;
  background:var(--brand);border-radius:7px;overflow:hidden;
}
.cqbtn{
  width:28px;height:28px;background:none;border:none;
  color:#fff;font-size:.9rem;font-weight:700;
  display:flex;align-items:center;justify-content:center;
}
.cqbtn:hover{background:rgba(255,255,255,.15)}
.cqnum{min-width:20px;text-align:center;font-size:.8rem;font-weight:700;color:#fff}
.cart-foot{padding:16px 22px 22px;border-top:1px solid var(--border);flex-shrink:0}
.bill-row{display:flex;justify-content:space-between;font-size:.83rem;margin-bottom:7px;color:var(--sub)}
.bill-total{
  display:flex;justify-content:space-between;
  font-size:1rem;font-weight:700;
  padding-top:10px;border-top:1px dashed var(--border);margin-top:4px;margin-bottom:16px;
}
.btn-checkout{
  width:100%;padding:14px;background:var(--brand);color:#fff;
  border:none;border-radius:11px;font-weight:700;font-size:.93rem;
  transition:background .2s,transform .2s;
}
.btn-checkout:hover{background:var(--brand-d);transform:translateY(-1px)}

/* Checkout confirmation view */
.checkout-view{display:none;flex-direction:column;flex:1;overflow:hidden;padding:18px 22px 22px}
.osum-title{font-size:.95rem;font-weight:700;margin-bottom:14px}
.osum-list{flex:1;overflow-y:auto;max-height:42vh}
.osum-item{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px dashed #f0f0f0;font-size:.83rem}
.osum-box{background:#fff5f5;border-radius:10px;padding:14px 16px;margin:12px 0}
.btn-back{width:100%;padding:9px;background:transparent;border:none;color:#aaa;text-decoration:underline;font-size:.82rem;margin-bottom:10px}
.btn-pay{width:100%;padding:13px;background:var(--brand);color:#fff;border:none;border-radius:11px;font-weight:700;font-size:.92rem;transition:.2s}
.btn-pay:hover{background:var(--brand-d)}
.btn-pay:disabled{opacity:.6;cursor:not-allowed}
.checkout-fields{display:flex;flex-direction:column;gap:8px;margin-bottom:14px}
.checkout-fields input{
  width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;
  font-family:inherit;font-size:.85rem;outline:none;transition:.18s;
}
.checkout-fields input:focus{border-color:var(--brand)}
.checkout-fields label{font-size:.72rem;font-weight:700;color:var(--sub);text-transform:uppercase;letter-spacing:.4px;margin-bottom:-4px}

/* ─── TOAST ─── */
.toast{
  position:fixed;bottom:24px;left:50%;
  transform:translateX(-50%) translateY(12px);
  background:#1c1c1e;color:#fff;
  border-radius:50px;padding:9px 20px;
  font-size:.82rem;font-weight:500;z-index:9999;
  opacity:0;pointer-events:none;
  transition:opacity .25s,transform .3s var(--ease);
  white-space:nowrap;
}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}

/* ─── SEARCH RESULTS ─── */
.search-results{
  background:var(--surface);
  border-radius:12px;border:1px solid var(--border);
  margin:16px 0;
  overflow:hidden;
  display:none;
}
.search-results.show{display:block}
.sr-head{padding:12px 16px;font-size:.82rem;font-weight:700;color:var(--sub);border-bottom:1px solid var(--border)}

/* ─── MOBILE BOTTOM NAV ─── */
.mob-cart-bar{
  display:none;
  position:fixed;bottom:0;left:0;right:0;z-index:900;
  background:var(--brand);color:#fff;padding:14px 20px;
  align-items:center;justify-content:space-between;
  box-shadow:0 -4px 20px rgba(226,55,68,.3);
}
.mob-cart-bar.show{display:flex}
.mcb-left{font-size:.85rem;font-weight:600}
.mcb-right{font-size:.85rem;font-weight:600;opacity:.85}

/* ─── RESPONSIVE ─── */
@media(max-width:900px){
  .page-body{padding:0 12px 100px}
  .mob-cart-bar{display:flex}
  .banner-img{width:64px;height:64px}
  .banner-name{font-size:1.1rem}
}
@media(max-width:600px){
  .hbrand-sub,.hbtn-outline{display:none}
  .cart-panel{width:100%;right:-100%}
  .menu-drawer{width:100%;right:-100%}
  .fi-img,.fi-img-placeholder{width:96px;height:72px}
  .fi-right{width:96px}
}
</style>
</head>
<body>

<div class="toast" id="toast"></div>
<div class="cart-overlay" id="cartOverlay" onclick="toggleCart()"></div>

<!-- ═══════════════════════════════
     CART PANEL
════════════════════════════════ -->
<div class="cart-panel" id="cartPanel">
  <div class="cart-head">
    <div>
      <div class="cart-head-title">🛒 Your Order</div>
      <div class="cart-head-sub" id="cartRestName">Sweet Delights Bakery</div>
    </div>
    <button class="cart-close" onclick="toggleCart()">✕</button>
  </div>

  <!-- Basket view -->
  <div id="basketView" style="display:flex;flex-direction:column;flex:1;overflow:hidden">
    <div class="cart-body" id="cartBody">
      <div class="cart-empty">
        <div class="cart-empty-icon">🛍️</div>
        <div style="font-weight:600;margin-bottom:6px">Your cart is empty</div>
        <div style="font-size:.78rem">Add items to get started</div>
      </div>
    </div>
    <div class="cart-foot">
      <div class="bill-row"><span>Item total</span><span id="billItems">₹0</span></div>
      <div class="bill-row"><span>Delivery fee</span><span style="color:var(--green);font-weight:600">FREE</span></div>
      <div class="bill-row"><span>Taxes & charges</span><span id="billTax">₹0</span></div>
      <div class="bill-total"><span>To Pay</span><span id="billTotal" style="color:var(--brand)">₹0</span></div>
      <button class="btn-checkout" onclick="goCheckout()">Proceed to Pay →</button>
    </div>
  </div>

  <!-- Checkout confirm view -->
  <div class="checkout-view" id="checkoutView">
    <div class="osum-title">Order Summary</div>
    <div class="osum-list" id="osumList"></div>
    <div class="osum-box">
      <div style="display:flex;justify-content:space-between;font-weight:700;font-size:.95rem">
        <span>Grand Total</span><span id="osumTotal" style="color:var(--brand)">₹0</span>
      </div>
    </div>
    <div class="checkout-fields">
      <label for="custName">Name</label>
      <input type="text" id="custName" placeholder="Your name">
      <label for="custPhone">Phone</label>
      <input type="text" id="custPhone" placeholder="Phone number">
      <label for="custAddress">Delivery address</label>
      <input type="text" id="custAddress" placeholder="Delivery address">
    </div>
    <button class="btn-back" onclick="goBasket()">← Edit Order</button>
    <button class="btn-pay" id="payBtn" onclick="placeOrder()">✓ Place Order</button>
  </div>
</div>

<!-- ═══════════════════════════════
     HEADER
════════════════════════════════ -->
<header class="header">
  <div class="header-inner">
    <a href="#" class="hbrand">
      🍰
      <div>Sweet Delights<span class="hbrand-sub">Artisan Bakery & Café</span></div>
    </a>

    <div class="hsearch">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
      <input type="text" id="searchInput" placeholder="Search for dishes, e.g. chocolate cake, mojito…" oninput="handleSearch(this.value)" onfocus="showSearch()" onblur="hideSearch()">
    </div>

    <div class="hright">
      <a class="hbtn hbtn-outline" href="?view=orders" target="_blank">📋 View Orders</a>
      <button class="hbtn hbtn-cart" onclick="toggleCart()">
        🛒 Cart <span class="cart-count" id="headerCartCount">0</span>
      </button>
    </div>
  </div>
</header>

<!-- ═══════════════════════════════
     RESTAURANT BANNER
════════════════════════════════ -->
<div class="banner">
  <div class="offer-strip">🎁 Free delivery on orders above ₹599 &nbsp;|&nbsp; 🕐 20–30 min delivery &nbsp;|&nbsp; Use code SWEET10 for 10% off</div>
  <div class="banner-inner">
    <img class="banner-img" src="https://images.unsplash.com/photo-1578985545062-69928b1d9587?w=200&q=80" alt="Sweet Delights">
    <div class="banner-info">
      <div class="banner-name">Sweet Delights Bakery & Café</div>
      <div class="banner-tags">
        <?php foreach(array_slice($categories,0,6) as $c): ?>
        <span class="btag"><?= htmlspecialchars($c) ?></span>
        <?php endforeach; ?>
      </div>
      <div class="banner-meta">
        <span class="rating-badge">★ 4.6</span>
        <span class="bm-item">📦 2.8k orders</span>
        <span class="bm-item">🕐 20–30 min</span>
        <span class="bm-item">₹0 delivery</span>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════
     SEARCH RESULTS OVERLAY
════════════════════════════════ -->
<div style="max-width:1280px;margin:0 auto;padding:0 20px;position:relative;z-index:700">
  <div class="search-results" id="searchResults">
    <div class="sr-head">Search Results</div>
    <div id="searchResultsBody" style="max-height:340px;overflow-y:auto"></div>
  </div>
</div>

<!-- ═══════════════════════════════
     PAGE BODY
════════════════════════════════ -->
<div class="page-body">

  <!-- ── LEFT: MENU ITEMS ── -->
  <div class="menu-content" id="menuContent">

    <?php foreach($grouped as $catName => $items):
      $icon = $icons[$catName] ?? '🧁';
      $catId = 'cat-'.preg_replace('/\s+/','',$catName);
      $fbImg = $fallback[$catName] ?? '';
    ?>
    <div class="cat-section" id="<?= $catId ?>" data-cat="<?= htmlspecialchars($catName) ?>">

      <!-- ACCORDION HEADER -->
      <div class="cat-accord" onclick="toggleCat('<?= $catId ?>')">
        <div class="cat-accord-left">
          <span class="cat-accord-icon"><?= $icon ?></span>
          <span class="cat-accord-name"><?= htmlspecialchars($catName) ?></span>
          <span class="cat-accord-count"><?= count($items) ?></span>
        </div>
        <button class="cat-accord-arrow" tabindex="-1">▾</button>
      </div>

      <!-- ITEMS LIST -->
      <div class="cat-items" id="items-<?= $catId ?>">
        <?php foreach($items as $p):
          $img      = getImg($p['name'],$p['category'],$imageMap,$fallback);
          $fb       = $fallback[$p['category']] ?? '';
          $hasMulti = !empty($p['price_small']) && !empty($p['price_medium']);
          $price    = !empty($p['price_small']) ? $p['price_small'] : $p['price'];
          $isBest   = in_array($p['name'], [
            'Chocolate Fudge Cake 1kg','Red Velvet Cupcakes 6pcs',
            'Butter Croissants 4pcs','Classic Fudge Brownie',
            'Chocolate Milkshake 300ml','Classic Mint Mojito 300ml',
            'French Fries Regular','Chocolate Chip Cookies 12pcs',
            'Blueberry Muffins 2pcs','Mango Ice Cream',
            'Oreo Milkshake 300ml','Paneer Tikka 8pcs'
          ]);
        ?>
        <div class="food-item" id="fi-<?= $p['id'] ?>">

          <!-- LEFT: info -->
          <div class="fi-info">
            <div class="fi-badges">
              <?php if($p['is_vegetarian']): ?>
              <span class="veg-dot v" title="Vegetarian"></span>
              <?php endif; ?>
              <?php if($p['is_eggless']): ?>
              <span class="veg-dot e" title="Eggless"></span>
              <span class="fi-eggless">EGGLESS</span>
              <?php endif; ?>
              <?php if($isBest): ?>
              <span class="fi-bestseller">🔥 Bestseller</span>
              <?php endif; ?>
            </div>
            <div class="fi-name"><?= htmlspecialchars($p['name']) ?></div>
            <div class="fi-price">₹<?= number_format($price, 0) ?></div>
            <div class="fi-desc"><?= htmlspecialchars($p['description']) ?></div>

            <?php if($hasMulti): ?>
            <div class="fi-sizes" id="sizes-<?= $p['id'] ?>">
              <button class="sz-btn active"
                data-price="<?= $p['price_small'] ?>"
                onclick="setSize(<?= $p['id'] ?>, this, <?= $p['price_small'] ?>, 'Small')">
                Small ₹<?= number_format($p['price_small'],0) ?>
              </button>
              <button class="sz-btn"
                data-price="<?= $p['price_medium'] ?>"
                onclick="setSize(<?= $p['id'] ?>, this, <?= $p['price_medium'] ?>, 'Medium')">
                Medium ₹<?= number_format($p['price_medium'],0) ?>
              </button>
              <?php if(!empty($p['price_large'])): ?>
              <button class="sz-btn"
                data-price="<?= $p['price_large'] ?>"
                onclick="setSize(<?= $p['id'] ?>, this, <?= $p['price_large'] ?>, 'Large')">
                Large ₹<?= number_format($p['price_large'],0) ?>
              </button>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>

          <!-- RIGHT: image + add/qty -->
          <div class="fi-right">
            <img class="fi-img"
              src="<?= htmlspecialchars($img) ?>"
              alt="<?= htmlspecialchars($p['name']) ?>"
              loading="lazy"
              onerror="this.onerror=null;this.src='<?= htmlspecialchars($fb) ?>'">

            <div class="fi-add-wrap">
              <button class="fi-add-btn" id="add-<?= $p['id'] ?>"
                onclick="addItem(<?= $p['id'] ?>)">ADD</button>
              <div class="fi-qty" id="qty-<?= $p['id'] ?>">
                <button class="qbtn" onclick="decItem(<?= $p['id'] ?>)">−</button>
                <span class="qnum" id="qnum-<?= $p['id'] ?>">1</span>
                <button class="qbtn" onclick="incItem(<?= $p['id'] ?>)">+</button>
              </div>
            </div>
          </div>

        </div><!-- /food-item -->
        <?php endforeach; ?>
      </div><!-- /cat-items -->
    </div><!-- /cat-section -->
    <?php endforeach; ?>

  </div><!-- /menu-content -->

  <aside class="sidebar"></aside>

</div><!-- /page-body -->

<!-- ═══════════════════════════════
     FLOATING MENU BUTTON (FAB)
════════════════════════════════ -->
<button class="menu-fab" onclick="toggleMenuDrawer()" id="menuFab">
  <span class="menu-fab-icon">☰</span> Menu
</button>

<!-- ═══════════════════════════════
     MENU DRAWER
════════════════════════════════ -->
<div class="menu-overlay" id="menuOverlay" onclick="toggleMenuDrawer()"></div>
<div class="menu-drawer" id="menuDrawer">
  <div class="md-head">
    <span class="md-title">📋 Browse Menu</span>
    <button class="md-close" onclick="toggleMenuDrawer()">✕</button>
  </div>
  <div class="md-list" id="mdList">
    <?php foreach($grouped as $catName => $items):
      $icon  = $icons[$catName] ?? '🧁';
      $catId = 'cat-'.preg_replace('/\s+/','',$catName);
      $sample = $items[0] ?? null;
      $sImg   = $sample ? getImg($sample['name'],$sample['category'],$imageMap,$fallback) : '';
      $sfb    = $fallback[$catName] ?? '';
    ?>
    <div class="md-item" id="md-<?= $catId ?>" onclick="jumpTocat('<?= $catId ?>')">
      <img class="md-img"
        src="<?= htmlspecialchars($sImg) ?>"
        alt="<?= htmlspecialchars($catName) ?>"
        loading="lazy"
        onerror="this.onerror=null;this.src='<?= htmlspecialchars($sfb) ?>'">
      <div>
        <div class="md-item-name"><?= $icon ?> <?= htmlspecialchars($catName) ?></div>
        <div class="md-item-count"><?= count($items) ?> items</div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Mobile bottom bar -->
<div class="mob-cart-bar" id="mobCartBar" onclick="toggleCart()">
  <div class="mcb-left" id="mobCartInfo">0 items</div>
  <div class="mcb-right">View Cart →</div>
</div>

<!-- ═══════════════════════════════
     JAVASCRIPT
════════════════════════════════ -->
<script>
const products = <?= json_encode($jsProds, JSON_UNESCAPED_UNICODE) ?>;

// Per-item selected size tracking
const itemSizes = {};
products.forEach(p => {
  itemSizes[p.id] = { price: p.price, label: '' };
});

function setSize(id, btn, price, label) {
  itemSizes[id] = { price, label };
  btn.closest('.fi-sizes').querySelectorAll('.sz-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  const fi = document.getElementById('fi-'+id);
  if(fi) fi.querySelector('.fi-price').textContent = '₹' + price.toLocaleString('en-IN');
}

// ── CART ──
let cart = {};

function getEffectiveName(id) {
  const p = products.find(x => x.id === id);
  const sz = itemSizes[id];
  return p.name + (sz && sz.label ? ` (${sz.label})` : '');
}
function getEffectivePrice(id) {
  return itemSizes[id]?.price ?? products.find(x=>x.id===id).price;
}

function addItem(id) {
  cart[id] = 1;
  document.getElementById('add-'+id).style.display = 'none';
  const qEl = document.getElementById('qty-'+id);
  qEl.classList.add('show');
  document.getElementById('qnum-'+id).textContent = 1;
  renderCart();
  showToast('Added to cart 🎉');
  flashCartBtn();
}

function incItem(id) {
  cart[id] = (cart[id] || 0) + 1;
  document.getElementById('qnum-'+id).textContent = cart[id];
  renderCart();
  flashCartBtn();
}

function decItem(id) {
  cart[id] = (cart[id] || 1) - 1;
  if(cart[id] <= 0) {
    delete cart[id];
    document.getElementById('add-'+id).style.display = '';
    document.getElementById('qty-'+id).classList.remove('show');
  } else {
    document.getElementById('qnum-'+id).textContent = cart[id];
  }
  renderCart();
}

function renderCart() {
  const ids = Object.keys(cart);
  const total = ids.reduce((s,id) => s + getEffectivePrice(+id) * cart[id], 0);
  const tax = Math.round(total * 0.05);
  const qty = ids.reduce((s,id) => s + cart[id], 0);

  document.getElementById('headerCartCount').textContent = qty;
  document.getElementById('billItems').textContent = '₹'+total.toLocaleString('en-IN');
  document.getElementById('billTax').textContent = '₹'+tax.toLocaleString('en-IN');
  document.getElementById('billTotal').textContent = '₹'+(total+tax).toLocaleString('en-IN');

  const body = document.getElementById('cartBody');
  if(!ids.length) {
    body.innerHTML = `<div class="cart-empty"><div class="cart-empty-icon">🛍️</div><div style="font-weight:600;margin-bottom:6px">Your cart is empty</div><div style="font-size:.78rem">Add items to get started</div></div>`;
  } else {
    body.innerHTML = ids.map(id => {
      const p = products.find(x => x.id === +id);
      const price = getEffectivePrice(+id);
      const name  = getEffectiveName(+id);
      return `<div class="ci">
        <img class="ci-img" src="${p.img}" alt="${p.name}" onerror="this.src='${p.fb}'">
        <div class="ci-info">
          <div class="ci-name">${name}</div>
          <div class="ci-price">₹${price.toLocaleString('en-IN')} × ${cart[id]} = ₹${(price*cart[id]).toLocaleString('en-IN')}</div>
        </div>
        <div class="ci-qty">
          <button class="cqbtn" onclick="decItem(${id})">−</button>
          <span class="cqnum">${cart[id]}</span>
          <button class="cqbtn" onclick="incItem(${id})">+</button>
        </div>
      </div>`;
    }).join('');
  }

  const mob = document.getElementById('mobCartBar');
  if(qty > 0) {
    mob.classList.add('show');
    document.getElementById('mobCartInfo').textContent = qty + ' item' + (qty>1?'s':'') + ' · ₹'+(total+tax).toLocaleString('en-IN');
  } else {
    mob.classList.remove('show');
  }
}

// ── CART PANEL ──
function toggleCart() {
  const p = document.getElementById('cartPanel');
  p.classList.toggle('open');
  document.getElementById('cartOverlay').style.display = p.classList.contains('open') ? 'block' : 'none';
  document.getElementById('basketView').style.display = 'flex';
  document.getElementById('checkoutView').style.display = 'none';
}

function goCheckout() {
  const ids = Object.keys(cart);
  if(!ids.length) { showToast('Your cart is empty!'); return; }
  let total = 0;
  document.getElementById('osumList').innerHTML = ids.map(id => {
    const p = products.find(x=>x.id===+id);
    const price = getEffectivePrice(+id);
    const sub = price * cart[id]; total += sub;
    return `<div class="osum-item"><span><b>${cart[id]}×</b> ${getEffectiveName(+id)}</span><b>₹${sub.toLocaleString('en-IN')}</b></div>`;
  }).join('');
  const tax = Math.round(total*0.05);
  document.getElementById('osumTotal').textContent = '₹'+(total+tax).toLocaleString('en-IN');
  document.getElementById('basketView').style.display = 'none';
  document.getElementById('checkoutView').style.display = 'flex';
}

function goBasket() {
  document.getElementById('checkoutView').style.display = 'none';
  document.getElementById('basketView').style.display = 'flex';
}

async function placeOrder() {
  const ids = Object.keys(cart);
  if(!ids.length) { showToast('Your cart is empty!'); return; }

  const items = ids.map(id => ({
    id: +id,
    name: getEffectiveName(+id),
    size: itemSizes[id]?.label || null,
    qty: cart[id],
    price: getEffectivePrice(+id)
  }));

  const payload = {
    items,
    customer_name: document.getElementById('custName').value.trim(),
    customer_phone: document.getElementById('custPhone').value.trim(),
    customer_address: document.getElementById('custAddress').value.trim()
  };

  const payBtn = document.getElementById('payBtn');
  payBtn.disabled = true;
  payBtn.textContent = 'Placing order…';

  try {
    const res = await fetch('?action=place_order', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json();

    if(!data.success) {
      throw new Error(data.error || 'Could not save order');
    }

    document.getElementById('cartPanel').innerHTML = `
      <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;text-align:center;padding:40px">
        <div style="font-size:5rem;margin-bottom:16px">🎉</div>
        <h2 style="font-size:1.4rem;font-weight:800;margin-bottom:8px">Order Placed!</h2>
        <p style="color:#6b6b6b;margin-bottom:6px;font-size:.9rem">Order #${data.order_id} is confirmed and<br>being freshly prepared.</p>
        <p style="color:var(--brand);font-weight:700;font-size:.9rem;margin-bottom:28px">Estimated delivery: 25–35 min 🛵</p>
        <button onclick="location.reload()"
          style="background:var(--brand);color:#fff;border:none;padding:12px 32px;border-radius:50px;font-size:.9rem;font-weight:700;cursor:pointer">
          Back to Menu
        </button>
      </div>`;
    document.getElementById('cartOverlay').style.display = 'none';
    cart = {};
  } catch(err) {
    showToast('Order failed: ' + err.message);
    payBtn.disabled = false;
    payBtn.textContent = '✓ Place Order';
  }
}

// ── MENU DRAWER ──
function toggleMenuDrawer() {
  const drawer  = document.getElementById('menuDrawer');
  const overlay = document.getElementById('menuOverlay');
  const isOpen  = drawer.classList.contains('open');
  if(isOpen) {
    drawer.classList.remove('open');
    overlay.classList.remove('open');
  } else {
    drawer.classList.add('open');
    overlay.classList.add('open');
  }
}

function highlightDrawer(catId) {
  document.querySelectorAll('.md-item').forEach(s => s.classList.remove('active'));
  const md = document.getElementById('md-'+catId);
  if(md) { md.classList.add('active'); md.scrollIntoView({block:'nearest'}); }
}

// ── ACCORDION ──
window.addEventListener('DOMContentLoaded', () => {
  const first = document.querySelector('.cat-section');
  if(first) first.classList.add('open');
  initScrollSpy();
});

function toggleCat(catId) {
  const el = document.getElementById(catId);
  el.classList.toggle('open');
  highlightDrawer(catId);
}

// ── DRAWER JUMP ──
function jumpTocat(catId) {
  const el = document.getElementById(catId);
  if(!el) return;
  el.classList.add('open');
  const offset = el.getBoundingClientRect().top + window.scrollY - 80;
  window.scrollTo({ top: offset, behavior:'smooth' });
  highlightDrawer(catId);
  document.getElementById('menuDrawer').classList.remove('open');
  document.getElementById('menuOverlay').classList.remove('open');
}

// ── SCROLL SPY ──
function initScrollSpy() {
  const sections = document.querySelectorAll('.cat-section');
  const obs = new IntersectionObserver(entries => {
    entries.forEach(e => {
      if(e.isIntersecting) highlightDrawer(e.target.id);
    });
  }, { rootMargin: '-30% 0px -60% 0px' });
  sections.forEach(s => obs.observe(s));
}

// ── SEARCH ──
let searchVisible = false;
function showSearch() { searchVisible = true; handleSearch(document.getElementById('searchInput').value); }
function hideSearch() { setTimeout(() => { searchVisible = false; document.getElementById('searchResults').classList.remove('show'); }, 200); }

function handleSearch(val) {
  const v = val.trim().toLowerCase();
  const el = document.getElementById('searchResults');
  const body = document.getElementById('searchResultsBody');
  if(!v || !searchVisible) { el.classList.remove('show'); return; }
  const hits = products.filter(p => p.name.toLowerCase().includes(v) || p.desc.toLowerCase().includes(v)).slice(0,8);
  if(!hits.length) { el.classList.remove('show'); return; }
  body.innerHTML = hits.map(p => `
    <div style="display:flex;align-items:center;gap:12px;padding:10px 16px;border-bottom:1px solid #f5f5f5;cursor:pointer"
         onmousedown="searchJump('cat-${p.cat.replace(/\s+/g,'')}','fi-${p.id}')">
      <img src="${p.img}" onerror="this.src='${p.fb}'" style="width:44px;height:44px;border-radius:8px;object-fit:cover;flex-shrink:0;background:#f5f5f5">
      <div style="flex:1;min-width:0">
        <div style="font-size:.85rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${p.name}</div>
        <div style="font-size:.75rem;color:#6b6b6b;margin-top:1px">${p.cat} · ₹${p.price.toLocaleString('en-IN')}</div>
      </div>
      <button style="background:var(--brand);color:#fff;border:none;border-radius:7px;padding:5px 14px;font-size:.78rem;font-weight:700"
              onmousedown="event.stopPropagation();addItem(${p.id})">ADD</button>
    </div>`).join('');
  el.classList.add('show');
}

function searchJump(catId, fiId) {
  document.getElementById('searchResults').classList.remove('show');
  document.getElementById('searchInput').value = '';
  const cat = document.getElementById(catId);
  if(cat) cat.classList.add('open');
  const fi = document.getElementById(fiId);
  if(fi) { const top = fi.getBoundingClientRect().top + window.scrollY - 90; window.scrollTo({top, behavior:'smooth'}); }
  highlightDrawer(catId);
}

// ── FLASH ──
let toastTimer;
function showToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg; t.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 2000);
}
function flashCartBtn() {
  const b = document.querySelector('.hbtn-cart');
  b.style.transform = 'scale(1.1)';
  setTimeout(() => b.style.transform = '', 300);
}
</script>
</body>
</html>