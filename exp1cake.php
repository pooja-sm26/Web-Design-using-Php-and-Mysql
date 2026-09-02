<?php
// ============================================================
//  CAKE BUILDER — Grid View with Modal Customizer
//  PHP 7.4+ | MySQL/MariaDB
//  Change DB credentials below, import cake_builder.sql first
// ============================================================
ob_start(); // Buffer all output so stray PHP notices never corrupt JSON

define('DB_HOST', 'localhost');
define('DB_NAME', 'cake_builder');
define('DB_USER', 'root');
define('DB_PASS', '');

session_start();

function db(): PDO {
    static $pdo = null;
    if (!$pdo) {
        try {
            $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        } catch (PDOException $e) {
            if (!empty($_POST['action'])) {
                ob_clean();
                header('Content-Type: application/json');
                echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
                exit;
            }
            die('<div style="font:15px sans-serif;padding:40px;color:red">
                <b>Database Connection Error:</b> '.htmlspecialchars($e->getMessage()).
                '<br><br>Please check your DB credentials in cake_builder.php and ensure cake_builder.sql is imported.</div>');
        }
    }
    return $pdo;
}

function jout(array $d): void {
    ob_clean(); // Discard any stray output before sending JSON
    header('Content-Type: application/json');
    echo json_encode($d); exit;
}

// ── Helper: detect cupcake category ──────────────────────────
function isCupcakeCat(string $slug): bool {
    return stripos($slug, 'cupcake') !== false;
}

// ── AJAX ──────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'place_order') {
    try {
        $itemId    = (int)($_POST['item_id']          ?? 0);
        $name      = trim($_POST['customer_name']     ?? '');
        $phone     = trim($_POST['customer_phone']    ?? '');
        $email     = trim($_POST['customer_email']    ?? '');
        $addr      = trim($_POST['delivery_address']  ?? '');
        $ddate     = trim($_POST['delivery_date']     ?? '');
        $dtime     = trim($_POST['delivery_time']     ?? '');
        $egg       = in_array($_POST['egg_pref'] ?? '', ['with_egg','eggless'])
                     ? $_POST['egg_pref'] : 'with_egg';
        $isCupcake = (($_POST['is_cupcake'] ?? '0') === '1');
        $pieces    = max(1, (int)($_POST['pieces']    ?? 1));
        $weightKg  = max(0.5, (float)($_POST['weight_kg'] ?? 0.5));
        $msg       = trim($_POST['cake_message']      ?? '');
        $occasion  = trim($_POST['occasion']          ?? '');
        $dietReqs  = trim($_POST['diet_requirements'] ?? '');
        $notes     = trim($_POST['special_notes']     ?? '');

        if (!$name || !$phone)
            jout(['success'=>false,'message'=>'Name and phone number are required.']);
        if (!$itemId)
            jout(['success'=>false,'message'=>'No item selected.']);

        $ir = db()->prepare('SELECT name,base_price FROM cake_items WHERE id=? AND is_available=1');
        $ir->execute([$itemId]);
        $item = $ir->fetch();
        if (!$item) jout(['success'=>false,'message'=>'Item not found or unavailable.']);

        $baseP = $isCupcake
            ? round((float)$item['base_price'] * $pieces, 2)
            : round((float)$item['base_price'] * ($weightKg / 0.5), 2);

        $ings     = json_decode($_POST['ingredients'] ?? '[]', true) ?: [];
        $ingTotal = 0;
        $ref      = 'CAKE-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,7));

        db()->prepare('INSERT INTO orders
            (order_ref,item_id,item_name,customer_name,customer_phone,
             customer_email,delivery_address,delivery_date,delivery_time,egg_preference,
             cake_weight_kg,cake_message,occasion,diet_requirements,special_notes,
             base_price,ingredient_total,total_price)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $ref, $itemId, $item['name'], $name, $phone,
                $email, $addr, $ddate ?: null, $dtime, $egg,
                $isCupcake ? null : $weightKg,
                $msg, $occasion, $dietReqs, $notes,
                $baseP, 0, 0
            ]);
        $oid = (int)db()->lastInsertId();

        $ins = db()->prepare('INSERT INTO order_ingredients
            (order_id,ingredient_id,ingredient_name,category,quantity,unit,unit_price,subtotal)
            VALUES(?,?,?,?,?,?,?,?)');
        foreach ($ings as $ing) {
            $iid = (int)($ing['id'] ?? 0);
            $qty = max(0, (float)($ing['qty'] ?? 0));
            if ($qty <= 0 || $iid <= 0) continue;
            $ir2 = db()->prepare('SELECT name,category,unit,unit_price FROM ingredients WHERE id=?');
            $ir2->execute([$iid]);
            $iRow = $ir2->fetch();
            if (!$iRow) continue;
            $sub = round($qty * (float)$iRow['unit_price'], 2);
            $ingTotal += $sub;
            $ins->execute([$oid, $iid, $iRow['name'], $iRow['category'],
                           $qty, $iRow['unit'], (float)$iRow['unit_price'], $sub]);
        }

        $total = $baseP + $ingTotal;
        db()->prepare('UPDATE orders SET ingredient_total=?,total_price=? WHERE id=?')
            ->execute([$ingTotal, $total, $oid]);

        jout(['success'=>true,'order_ref'=>$ref,'total'=>$total]);

    } catch (Throwable $e) {
        jout(['success'=>false,'message'=>'Server error: '.$e->getMessage()]);
    }
}
// ── DATA ──────────────────────────────────────────────────────
$categories  = db()->query('SELECT * FROM categories ORDER BY sort_order')->fetchAll();
$dietLabels  = db()->query('SELECT * FROM diet_labels ORDER BY id')->fetchAll();
$dietMap     = [];
foreach ($dietLabels as $dl) $dietMap[$dl['id']] = $dl;

$allItems    = db()->query('SELECT ci.*,c.name AS cat_name,c.slug AS cat_slug
    FROM cake_items ci
    JOIN categories c ON c.id=ci.category_id
    WHERE ci.is_available=1
    ORDER BY c.sort_order,ci.is_bestseller DESC,ci.name')->fetchAll();

$ingredients = db()->query('SELECT * FROM ingredients WHERE is_active=1 ORDER BY category,name')->fetchAll();
$ingByCat    = [];
foreach ($ingredients as $i) $ingByCat[$i['category']][] = $i;

$itemsByCat  = [];
foreach ($allItems as $it) $itemsByCat[$it['cat_slug']][] = $it;

$occasions = ['Birthday','Anniversary','Wedding','Baby Shower','Graduation',
              'Valentine','Farewell','Get Well Soon','Retirement','Promotion','Just Because','Other'];

$dietOptions = ['Gluten Free','Vegan','Sugar Free','Nut Free','Dairy Free',
                'Diabetic Friendly','Halal','Jain','Keto Friendly','Alcohol Free'];

// ── Custom image overrides ─────────────────────────────────────
// Maps item name (lowercase) => Unsplash image URL
$imageOverrides = [
    'salted caramel drip cake' => 'https://images.unsplash.com/photo-1606890737304-57a1ca8a5806?w=800&q=85',
    'apple cinnamon tart'      => 'https://images.unsplash.com/photo-1562440499-64c9a111f713?w=800&q=85',
    'kouign-amann'             => 'https://images.unsplash.com/photo-1558961363-fa8fdf82db35?w=800&q=85',
    'nutella swirl brownie'    => 'https://images.unsplash.com/photo-1606313564200-e75d5e30476c?w=800&q=85',
    'naked layer cake'         => 'https://images.unsplash.com/photo-1586788680434-30d324b2d46f?w=800&q=85',
    'photo cake'               => 'https://images.unsplash.com/photo-1535141192574-5d4897c12636?w=800&q=85',
];

function getItemImage(array $item, array $overrides): string {
    $key = strtolower(trim($item['name']));
    return $overrides[$key] ?? $item['image_url'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CakeAtelier — Build Your Cake</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,700;0,900;1,600&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ── RESET ── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --ink:#1C0F07;--mid:#4A2E1A;--muted:#8C6E5A;--faint:#C4A890;
  --cream:#FDF7F0;--warm:#F5EAD8;--card:#FFFFFF;
  --rose:#C05A42;--rosedark:#9A3E2A;--roselight:#FAEBE6;
  --gold:#B08020;--golddark:#7A5A10;
  --green:#2A6044;--red:#B02818;
  --border:#E8D5C0;--border2:#D8C0A8;
  --sh:0 2px 16px rgba(28,15,7,.10);
  --sh-md:0 6px 28px rgba(28,15,7,.14);
  --sh-lg:0 12px 56px rgba(28,15,7,.20);
  --sh-xl:0 24px 80px rgba(28,15,7,.28);
  --r:18px;--r-sm:12px;--r-xs:8px;
  --tr:.24s cubic-bezier(.4,0,.2,1);
}
html{scroll-behavior:smooth}
body{font-family:'DM Sans',sans-serif;background:var(--cream);color:var(--ink);min-height:100vh;overflow-x:hidden}
img{display:block;object-fit:cover}
::-webkit-scrollbar{width:5px}
::-webkit-scrollbar-thumb{background:var(--rose);border-radius:4px}

/* ── KEYFRAMES ── */
@keyframes fadeUp{from{opacity:0;transform:translateY(22px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes slideDown{from{opacity:0;transform:translateY(-14px)}to{opacity:1;transform:translateY(0)}}
@keyframes heroZoom{0%,100%{transform:scale(1.04)}50%{transform:scale(1.10)}}
@keyframes popIn{0%{opacity:0;transform:scale(.88) translateY(18px)}65%{transform:scale(1.02)}100%{opacity:1;transform:scale(1) translateY(0)}}
@keyframes cardIn{from{opacity:0;transform:translateY(28px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}
@keyframes ticker{from{transform:translateX(0)}to{transform:translateX(-50%)}}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.05)}}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-7px)}}
@keyframes shimmerBg{0%{background-position:-200% 0}100%{background-position:200% 0}}

/* ── HEADER ── */
.header{
  position:sticky;top:0;z-index:200;
  background:rgba(28,15,7,.97);
  backdrop-filter:blur(18px);
  border-bottom:1px solid rgba(255,255,255,.05);
  height:68px;display:flex;align-items:center;
  justify-content:space-between;padding:0 36px;
  box-shadow:0 2px 20px rgba(0,0,0,.4);
  animation:slideDown .45s ease both;
}
.logo{display:flex;align-items:center;gap:12px;text-decoration:none}
.logo-badge{
  width:40px;height:40px;border-radius:11px;overflow:hidden;
  border:1.5px solid rgba(192,90,66,.5);flex-shrink:0;
}
.logo-badge img{width:100%;height:100%}
.logo-name{font-family:'Playfair Display',serif;font-size:22px;font-weight:700;color:#fff;letter-spacing:-.3px}
.logo-name span{color:var(--rose)}
.logo-sub{font-size:10px;font-weight:400;color:rgba(255,255,255,.35);letter-spacing:2.5px;text-transform:uppercase;margin-top:-1px}
.hdr-right{display:flex;align-items:center;gap:14px}
.hdr-search{position:relative}
.hdr-search input{
  background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.1);
  border-radius:24px;padding:9px 16px 9px 38px;color:#fff;
  font-family:inherit;font-size:13px;outline:none;width:220px;transition:var(--tr);
}
.hdr-search input::placeholder{color:rgba(255,255,255,.28)}
.hdr-search input:focus{background:rgba(255,255,255,.13);border-color:rgba(192,90,66,.5);width:260px}
.hdr-search .si{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:15px;color:rgba(255,255,255,.3);pointer-events:none}
.hdr-badge{
  background:var(--rose);color:#fff;border-radius:24px;
  padding:7px 18px;font-size:13px;font-weight:600;
  display:flex;align-items:center;gap:7px;
  animation:pulse 4s ease infinite;
}
.hbnum{background:rgba(255,255,255,.22);border-radius:12px;padding:1px 8px;font-size:12px;font-weight:700}

/* ── HERO ── */
.hero{position:relative;height:420px;overflow:hidden;display:flex;align-items:center;justify-content:center}
.hero-bg{
  position:absolute;inset:0;
  background:url('https://images.unsplash.com/photo-1578985545062-69928b1d9587?w=1600&q=85') center/cover no-repeat;
  animation:heroZoom 12s ease-in-out infinite;
}
.hero-ov{
  position:absolute;inset:0;
  background:linear-gradient(135deg,rgba(28,15,7,.90) 0%,rgba(28,15,7,.58) 60%,rgba(74,46,26,.45) 100%);
}
.hero-cnt{position:relative;text-align:center;padding:0 24px;animation:fadeUp .8s ease .15s both}
.hero-pill{
  display:inline-block;border:1px solid rgba(192,90,66,.45);color:var(--rose);
  padding:5px 20px;border-radius:20px;font-size:11px;font-weight:600;
  letter-spacing:2.5px;text-transform:uppercase;margin-bottom:18px;
}
.hero h1{
  font-family:'Playfair Display',serif;
  font-size:clamp(38px,6vw,68px);font-weight:700;
  color:#fff;line-height:1.0;margin-bottom:12px;
  text-shadow:0 2px 24px rgba(0,0,0,.35);
}
.hero h1 em{color:var(--rose);font-style:italic}
.hero-sub{color:rgba(255,255,255,.58);font-size:15px;font-weight:300;max-width:480px;margin:0 auto 26px;letter-spacing:.15px}
.hero-stats{display:flex;justify-content:center;gap:36px}
.hs{text-align:center}
.hs-n{font-family:'Playfair Display',serif;font-size:28px;font-weight:700;color:var(--rose)}
.hs-l{font-size:10px;color:rgba(255,255,255,.38);letter-spacing:2px;text-transform:uppercase;margin-top:1px}

/* ── TICKER ── */
.ticker-wrap{background:var(--rose);overflow:hidden;height:34px;display:flex;align-items:center}
.ticker{display:flex;white-space:nowrap;animation:ticker 30s linear infinite}
.ti{padding:0 36px;font-size:12px;font-weight:600;color:#fff;letter-spacing:.4px;display:flex;align-items:center;gap:10px}
.td{width:4px;height:4px;border-radius:50%;background:rgba(255,255,255,.45)}

/* ── CATEGORY BAR ── */
.cat-bar{
  background:var(--card);border-bottom:1px solid var(--border);
  position:sticky;top:68px;z-index:100;padding:0 36px;
  box-shadow:var(--sh);
}
.cat-bar-inner{display:flex;gap:4px;overflow-x:auto;padding:12px 0;scrollbar-width:none}
.cat-bar-inner::-webkit-scrollbar{display:none}
.cb{
  flex-shrink:0;padding:8px 18px;border-radius:24px;border:none;background:transparent;
  font-family:'DM Sans',sans-serif;font-size:13px;font-weight:500;color:var(--muted);
  cursor:pointer;transition:var(--tr);white-space:nowrap;
}
.cb:hover{background:var(--warm);color:var(--ink)}
.cb.active{background:var(--ink);color:#fff;font-weight:600}
.cb-cnt{display:inline-block;border-radius:8px;padding:1px 7px;font-size:10px;margin-left:4px;
  background:var(--border);color:var(--muted);transition:var(--tr)}
.cb.active .cb-cnt{background:rgba(255,255,255,.2);color:rgba(255,255,255,.8)}

/* ── FILTER ROW ── */
.filter-row{
  display:flex;align-items:center;gap:10px;flex-wrap:wrap;
  padding:14px 36px;background:var(--cream);border-bottom:1px solid var(--border);
}
.filter-label{font-size:12px;font-weight:600;color:var(--muted);white-space:nowrap;text-transform:uppercase;letter-spacing:.7px}
.filter-chips{display:flex;flex-wrap:wrap;gap:6px}
.fchip{
  padding:5px 13px;border-radius:16px;
  border:1.5px solid var(--border);background:var(--card);
  font-size:12px;font-weight:500;color:var(--muted);
  cursor:pointer;transition:var(--tr);white-space:nowrap;
}
.fchip:hover{border-color:var(--rose);color:var(--rose)}
.fchip.active{background:var(--rose);border-color:var(--rose);color:#fff;font-weight:600}
.filter-clear{
  padding:5px 12px;border-radius:16px;border:1.5px solid var(--border);
  background:transparent;font-family:inherit;font-size:12px;color:var(--muted);
  cursor:pointer;transition:var(--tr);margin-left:auto;
}
.filter-clear:hover{border-color:var(--rose);color:var(--rose)}

/* ── MAIN ── */
.main{max-width:1320px;margin:0 auto;padding:36px 36px 100px}

.section-head{
  display:flex;align-items:center;margin-bottom:22px;
  animation:fadeUp .4s ease both;
}
.sh-bar{width:4px;height:28px;background:linear-gradient(180deg,var(--rose),var(--gold));border-radius:3px;margin-right:14px;flex-shrink:0}
.sh-title{font-family:'Playfair Display',serif;font-size:26px;font-weight:700;color:var(--ink)}
.sh-count{font-size:12px;color:var(--muted);margin-top:3px}
.sh-line{flex:1;height:1px;background:linear-gradient(90deg,var(--border),transparent);margin-left:18px}

/* ── GRID ── */
.items-grid{
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(252px,1fr));
  gap:20px;margin-bottom:54px;
}

/* ── ITEM CARD ── */
.item-card{
  background:var(--card);border-radius:var(--r);
  border:1px solid var(--border);overflow:hidden;cursor:pointer;
  transition:transform var(--tr),box-shadow var(--tr),border-color var(--tr);
  position:relative;animation:cardIn .5s ease both;
  animation-play-state:paused;
}
.item-card.in-view{animation-play-state:running}
.item-card:hover{transform:translateY(-7px) scale(1.015);box-shadow:var(--sh-lg);border-color:transparent}
.item-card:active{transform:translateY(-2px) scale(.99)}

/* card nth delays */
.items-grid .item-card:nth-child(1){animation-delay:.04s}
.items-grid .item-card:nth-child(2){animation-delay:.08s}
.items-grid .item-card:nth-child(3){animation-delay:.12s}
.items-grid .item-card:nth-child(4){animation-delay:.16s}
.items-grid .item-card:nth-child(5){animation-delay:.20s}
.items-grid .item-card:nth-child(6){animation-delay:.24s}
.items-grid .item-card:nth-child(n+7){animation-delay:.28s}

/* image */
.ic-img{width:100%;height:195px;overflow:hidden;position:relative;background:var(--warm)}
.ic-img img{
  width:100%;height:100%;object-fit:cover;
  transition:transform .55s cubic-bezier(.4,0,.2,1);
}
.item-card:hover .ic-img img{transform:scale(1.12)}

/* overlay */
.ic-ov{
  position:absolute;inset:0;
  background:linear-gradient(to top,rgba(28,15,7,.62) 0%,transparent 55%);
  opacity:0;transition:opacity var(--tr);
  display:flex;align-items:flex-end;justify-content:center;padding-bottom:14px;
}
.item-card:hover .ic-ov{opacity:1}
.ic-ov-btn{
  background:rgba(255,255,255,.95);color:var(--ink);
  padding:8px 22px;border-radius:20px;font-size:12.5px;font-weight:700;
  letter-spacing:.25px;transform:translateY(10px);transition:transform .3s ease;
}
.item-card:hover .ic-ov-btn{transform:translateY(0)}

/* badges */
.ic-badges{position:absolute;top:10px;left:10px;display:flex;gap:5px;flex-wrap:wrap;z-index:2}
.ibadge{
  font-size:10px;font-weight:700;letter-spacing:.35px;text-transform:uppercase;
  padding:3px 9px;border-radius:5px;backdrop-filter:blur(6px);
}
.ib-best{background:rgba(176,128,32,.88);color:#fff}
.ib-veg{background:rgba(42,96,68,.85);color:#fff}
/* Cupcake badge — pieces indicator */
.ib-piece{background:rgba(192,90,66,.88);color:#fff}

/* diet labels on card */
.ic-diet-labels{
  position:absolute;top:10px;right:10px;
  display:flex;flex-direction:column;gap:3px;z-index:2;align-items:flex-end;
}
.ic-dlabel{
  font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;
  padding:3px 7px;border-radius:4px;backdrop-filter:blur(6px);
  white-space:nowrap;
}

/* wish btn */
.ic-wish{
  position:absolute;bottom:12px;right:12px;
  width:30px;height:30px;border-radius:50%;
  background:rgba(255,255,255,.9);border:none;cursor:pointer;
  display:flex;align-items:center;justify-content:center;
  font-size:15px;transition:var(--tr);opacity:0;transform:scale(.8);
}
.item-card:hover .ic-wish{opacity:1;transform:scale(1)}
.ic-wish.liked{opacity:1;transform:scale(1);color:var(--rose)}
.ic-wish:hover{background:#fff;transform:scale(1.15)!important}

/* body */
.ic-body{padding:14px 16px 17px}
.ic-meta{display:flex;align-items:center;justify-content:space-between;margin-bottom:5px}
.ic-cat{font-size:10.5px;font-weight:700;color:var(--rose);text-transform:uppercase;letter-spacing:.8px}
.ic-rat{font-size:11.5px;color:var(--gold);font-weight:600}
.ic-name{
  font-family:'Playfair Display',serif;font-size:18px;font-weight:700;color:var(--ink);
  line-height:1.2;margin-bottom:5px;
}
.ic-desc{
  font-size:12px;color:var(--muted);line-height:1.55;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;
  margin-bottom:12px;
}
.ic-foot{display:flex;align-items:center;justify-content:space-between;gap:8px}
.ic-price{font-family:'Playfair Display',serif;font-size:20px;font-weight:700;color:var(--ink)}
.ic-price small{font-size:11px;color:var(--muted);font-family:'DM Sans',sans-serif;font-weight:400}
.ic-add{
  background:var(--ink);color:#fff;border:none;border-radius:var(--r-xs);
  padding:8px 16px;font-family:inherit;font-size:12.5px;font-weight:600;
  cursor:pointer;transition:var(--tr);letter-spacing:.15px;flex-shrink:0;
}
.ic-add:hover{background:var(--rose)}

/* no-results */
.no-results{
  display:none;text-align:center;padding:80px 24px;
  grid-column:1/-1;
}
.nr-img{width:110px;height:110px;border-radius:50%;overflow:hidden;margin:0 auto 18px;border:3px solid var(--border)}
.nr-img img{width:100%;height:100%}
.nr-title{font-family:'Playfair Display',serif;font-size:22px;font-weight:700;color:var(--ink);margin-bottom:6px}
.nr-sub{font-size:14px;color:var(--muted)}

/* ── MODAL BACKGROUND ── */
.modal-bg{
  position:fixed;inset:0;z-index:500;
  background:rgba(28,15,7,.72);
  backdrop-filter:blur(9px);
  display:none;align-items:center;justify-content:center;
  padding:20px;
  transition:opacity .28s ease;opacity:0;
}
.modal-bg.open{display:flex;opacity:1}
.modal-bg.closing{opacity:0}

/* ── MODAL ── */
.modal{
  background:var(--card);border-radius:24px;
  width:100%;max-width:780px;max-height:92vh;
  overflow:hidden;display:flex;flex-direction:column;
  box-shadow:var(--sh-xl);
  animation:popIn .38s cubic-bezier(.34,1.56,.64,1) both;
}

/* modal hero img */
.m-img-wrap{
  position:relative;height:230px;flex-shrink:0;overflow:hidden;
}
.m-img-wrap img{
  width:100%;height:100%;object-fit:cover;
  transition:transform 7s ease;
}
.modal-bg.open .m-img-wrap img{transform:scale(1.07)}
.m-img-ov{
  position:absolute;inset:0;
  background:linear-gradient(to top,rgba(28,15,7,.80) 0%,transparent 50%);
  display:flex;flex-direction:column;justify-content:flex-end;
  padding:22px 28px;
}
.m-cat-lbl{font-size:11px;font-weight:700;color:var(--rose);letter-spacing:1.5px;text-transform:uppercase;margin-bottom:4px}
.m-name{font-family:'Playfair Display',serif;font-size:26px;font-weight:700;color:#fff;line-height:1.1}
.m-desc{font-size:13px;color:rgba(255,255,255,.65);margin-top:5px;font-weight:300;line-height:1.5}

/* diet labels in modal image */
.m-dlabels{
  position:absolute;top:12px;right:12px;
  display:flex;flex-direction:column;gap:4px;align-items:flex-end;
}
.m-dl{
  font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;
  padding:3px 8px;border-radius:5px;backdrop-filter:blur(8px);
}

.m-close{
  position:absolute;top:12px;left:12px;
  width:34px;height:34px;border-radius:50%;
  background:rgba(255,255,255,.92);border:none;cursor:pointer;
  font-size:17px;font-weight:700;color:var(--ink);
  display:flex;align-items:center;justify-content:center;
  transition:var(--tr);z-index:3;
}
.m-close:hover{background:#fff;transform:rotate(90deg)}

/* modal body */
.m-body{
  flex:1;overflow-y:auto;padding:22px 28px;
  scrollbar-width:thin;scrollbar-color:var(--border) transparent;
}
.m-body::-webkit-scrollbar{width:4px}
.m-body::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px}

/* modal sections */
.ms{margin-bottom:24px}
.ms-title{
  font-family:'Playfair Display',serif;font-size:16px;font-weight:700;color:var(--ink);
  margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:8px;
}
.ms-title::before{content:'';width:3px;height:15px;background:var(--rose);border-radius:2px;flex-shrink:0}

/* egg */
.egg-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.egg-opt{border:2px solid var(--border);border-radius:var(--r-sm);overflow:hidden;cursor:pointer;transition:var(--tr)}
.egg-opt:hover{border-color:var(--rose)}
.egg-opt.sel-egg{border-color:#D47E12;box-shadow:0 0 0 2px rgba(212,126,18,.2)}
.egg-opt.sel-eggless{border-color:var(--green);box-shadow:0 0 0 2px rgba(42,96,68,.2)}
.eo-img{width:100%;height:86px;overflow:hidden}
.eo-img img{width:100%;height:100%;object-fit:cover}
.eo-body{padding:10px 12px}
.eo-lbl{font-weight:700;font-size:13.5px;color:var(--ink)}
.eo-sub{font-size:11px;color:var(--muted);margin-top:2px}

/* ── CUPCAKE PIECES SELECTOR ── */
#cupcakePiecesSection{display:none}
.pieces-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.pieces-price-preview{
  font-family:'Playfair Display',serif;font-size:14px;font-weight:700;color:var(--rose);
  background:var(--roselight);padding:4px 12px;border-radius:12px;
}
.pieces-presets{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:14px}
.ppreset{
  width:48px;height:48px;border-radius:12px;
  border:2px solid var(--border);background:var(--cream);
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  cursor:pointer;transition:var(--tr);
  font-family:'Playfair Display',serif;font-size:15px;font-weight:700;color:var(--mid);
  line-height:1;gap:2px;
}
.ppreset small{font-size:9px;font-weight:500;color:var(--muted);font-family:'DM Sans',sans-serif}
.ppreset:hover{border-color:var(--rose);color:var(--rose)}
.ppreset.active{background:var(--ink);color:#fff;border-color:var(--ink)}
.ppreset.active small{color:rgba(255,255,255,.6)}
.pieces-custom-row{
  display:flex;align-items:center;gap:14px;
  background:var(--warm);border-radius:var(--r-xs);padding:12px 16px;
}
.pieces-ctrl{display:flex;align-items:center;gap:10px}
.pcb{
  width:36px;height:36px;border-radius:50%;
  border:2px solid var(--border);background:#fff;
  font-size:20px;cursor:pointer;transition:var(--tr);
  display:flex;align-items:center;justify-content:center;color:var(--mid);
  font-weight:300;flex-shrink:0;
}
.pcb:hover{border-color:var(--rose);color:var(--rose);background:var(--roselight)}
.pcv{
  min-width:44px;text-align:center;
  font-family:'Playfair Display',serif;font-size:26px;font-weight:700;color:var(--ink);
}
.pieces-unit-label{font-size:13px;font-weight:600;color:var(--muted);flex:1}
.pieces-subtotal{
  font-family:'Playfair Display',serif;font-size:15px;font-weight:700;color:var(--ink);
  text-align:right;white-space:nowrap;
}
.pieces-subtotal small{display:block;font-size:10px;font-family:'DM Sans',sans-serif;font-weight:400;color:var(--muted)}
.pieces-manual-row{
  display:flex;align-items:center;gap:10px;margin-top:10px;
  padding:10px 16px;border:1.5px dashed var(--border);border-radius:var(--r-xs);
  background:var(--cream);
}
.pieces-manual-row label{font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.7px;white-space:nowrap;flex:1}
.pinput{
  width:90px;padding:8px 12px;border:1.5px solid var(--border);border-radius:var(--r-xs);
  font-family:'Playfair Display',serif;font-size:16px;font-weight:700;color:var(--ink);
  background:#fff;outline:none;transition:var(--tr);text-align:center;
}
.pinput:focus{border-color:var(--rose);background:#fff;box-shadow:0 0 0 3px rgba(192,90,66,.1)}

/* weight */
#weightSection{display:block}
.weight-presets{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:12px}
.wpreset{
  padding:7px 15px;border-radius:20px;
  border:1.5px solid var(--border);background:var(--cream);
  font-size:12.5px;font-weight:500;cursor:pointer;transition:var(--tr);color:var(--mid);
}
.wpreset:hover{border-color:var(--rose);color:var(--rose)}
.wpreset.active{background:var(--ink);color:#fff;border-color:var(--ink)}
.weight-custom-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.weight-custom-row label{font-size:12px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;white-space:nowrap}
.winput{
  width:100px;padding:8px 12px;border:1.5px solid var(--border);border-radius:var(--r-xs);
  font-family:inherit;font-size:14px;font-weight:600;color:var(--ink);
  background:var(--cream);outline:none;transition:var(--tr);text-align:center;
}
.winput:focus{border-color:var(--rose);background:#fff}
.serves-note{font-size:11.5px;color:var(--muted);margin-top:7px;font-style:italic}

/* diet requirement checkboxes */
.diet-chips{display:flex;flex-wrap:wrap;gap:7px}
.dchip{
  padding:6px 13px;border-radius:16px;
  border:1.5px solid var(--border);background:var(--cream);
  font-size:12px;font-weight:500;color:var(--muted);
  cursor:pointer;transition:var(--tr);user-select:none;
}
.dchip:hover{border-color:var(--rose);color:var(--rose)}
.dchip.active{background:var(--green);border-color:var(--green);color:#fff;font-weight:600}

/* ingredients accordion */
.ing-acc{border:1px solid var(--border);border-radius:var(--r-sm);overflow:hidden;margin-bottom:10px}
.ing-acc-head{
  display:flex;align-items:center;gap:11px;
  padding:11px 15px;background:var(--warm);cursor:pointer;transition:var(--tr);
}
.ing-acc-head:hover{background:var(--border)}
.ing-acc.open .ing-acc-head{background:var(--warm)}
.iah-thumb{width:32px;height:32px;border-radius:7px;overflow:hidden;flex-shrink:0;border:1px solid var(--border)}
.iah-thumb img{width:100%;height:100%;object-fit:cover}
.iah-lbl{font-weight:600;font-size:13.5px;color:var(--ink);flex:1}
.iah-cnt{font-size:11px;color:var(--rose);font-weight:600;background:var(--roselight);padding:2px 8px;border-radius:10px;white-space:nowrap}
.iah-arr{color:var(--muted);transition:transform var(--tr);font-size:12px;margin-left:3px}
.ing-acc.open .iah-arr{transform:rotate(180deg)}

.ing-list{display:none}
.ing-acc.open .ing-list{display:block}

.ing-item{
  display:flex;align-items:center;
  border-top:1px solid var(--border);background:#fff;transition:background var(--tr);
}
.ing-item:hover{background:var(--cream)}
.ing-item.has-qty{background:#FFF3EF}
.ii-img{width:54px;height:54px;flex-shrink:0;overflow:hidden}
.ii-img img{width:100%;height:100%;object-fit:cover}
.ii-info{flex:1;padding:9px 12px;min-width:0}
.ii-name{font-weight:600;font-size:13px;color:var(--ink)}
.ii-price{font-size:11px;color:var(--gold);font-weight:600;margin-top:2px}
.ii-ctrl{padding:0 13px;display:flex;align-items:center;gap:8px;flex-shrink:0}
.qb{
  width:28px;height:28px;border-radius:50%;
  border:1.5px solid var(--border);background:transparent;
  font-size:17px;cursor:pointer;transition:var(--tr);
  display:flex;align-items:center;justify-content:center;color:var(--mid);
}
.qb:hover{border-color:var(--rose);color:var(--rose);background:var(--roselight)}
.qv{min-width:22px;text-align:center;font-size:14px;font-weight:700;color:var(--ink)}

/* form */
.fg-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:520px){.fg-grid{grid-template-columns:1fr}}
.fg{display:flex;flex-direction:column;gap:5px}
.fg.full{grid-column:1/-1}
.fg label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.8px}
.fg input,.fg select,.fg textarea{
  padding:10px 12px;border:1.5px solid var(--border);border-radius:var(--r-xs);
  font-family:'DM Sans',sans-serif;font-size:13.5px;color:var(--ink);
  background:var(--cream);outline:none;transition:var(--tr);
}
.fg input:focus,.fg select:focus,.fg textarea:focus{border-color:var(--rose);background:#fff}
.fg textarea{resize:none}
.ferr{font-size:11px;color:var(--red);display:none;margin-top:2px}
.ferr.show{display:block}

/* msg */
.msg-wrap{position:relative}
.msg-wrap textarea{width:100%;padding:11px 13px;border:1.5px solid var(--border);border-radius:var(--r-xs);
  font-family:'DM Sans',sans-serif;font-size:13.5px;color:var(--ink);background:var(--cream);
  outline:none;transition:var(--tr);resize:none;}
.msg-wrap textarea:focus{border-color:var(--rose);background:#fff}
.msg-cnt{position:absolute;bottom:9px;right:11px;font-size:10px;color:var(--muted)}

/* ── PRICE BAR ── */
.price-bar{
  position:sticky;bottom:0;background:#fff;
  border-top:1px solid var(--border);
  padding:14px 28px;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;
  box-shadow:0 -4px 24px rgba(28,15,7,.1);
}
.pb-price{display:flex;flex-direction:column}
.pb-lbl{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;font-weight:600}
.pb-val{font-family:'Playfair Display',serif;font-size:28px;font-weight:700;color:var(--ink);line-height:1}
.pb-break{font-size:11px;color:var(--muted);margin-top:2px}
.pb-btn{
  background:linear-gradient(135deg,var(--ink) 0%,var(--mid) 100%);
  color:#fff;border:none;border-radius:var(--r-sm);
  padding:13px 30px;font-family:'DM Sans',sans-serif;font-size:14px;font-weight:700;
  cursor:pointer;transition:var(--tr);position:relative;overflow:hidden;
  display:flex;align-items:center;gap:10px;
}
.pb-btn::after{content:'';position:absolute;inset:0;background:linear-gradient(135deg,var(--rose),var(--rosedark));opacity:0;transition:opacity var(--tr)}
.pb-btn:hover::after{opacity:1}
.pb-btn:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(28,15,7,.3)}
.pb-btn span{position:relative;z-index:1}
.pb-btn:disabled{opacity:.6;cursor:not-allowed;transform:none}
.spinner{width:17px;height:17px;border-radius:50%;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;animation:spin .7s linear infinite;display:none}

/* ── SUCCESS ── */
.suc-overlay{position:fixed;inset:0;z-index:600;background:rgba(28,15,7,.85);display:none;align-items:center;justify-content:center;padding:20px}
.suc-overlay.show{display:flex}
.suc-card{background:#fff;border-radius:24px;max-width:500px;width:100%;overflow:hidden;box-shadow:var(--sh-xl);animation:popIn .5s cubic-bezier(.34,1.56,.64,1) both}
.suc-img{width:100%;height:190px;overflow:hidden}
.suc-img img{width:100%;height:100%;object-fit:cover;animation:heroZoom 8s ease-in-out infinite}
.suc-body{padding:28px;text-align:center}
.suc-icon{font-size:48px;margin-bottom:10px;animation:float 2s ease-in-out infinite}
.suc-title{font-family:'Playfair Display',serif;font-size:26px;font-weight:700;color:var(--ink);margin-bottom:5px}
.suc-ref{font-size:13px;color:var(--muted);margin-bottom:10px}
.suc-ref b{color:var(--rose);font-size:15px}
.suc-total{display:inline-block;background:var(--ink);color:var(--cream);padding:9px 28px;border-radius:28px;font-family:'Playfair Display',serif;font-size:22px;font-weight:700;margin:8px 0 16px;animation:pulse 2.5s ease infinite}
.suc-msg{color:var(--muted);font-size:13px;line-height:1.6;max-width:340px;margin:0 auto 20px}
.suc-btns{display:flex;gap:10px;justify-content:center}
.suc-btn-new{background:var(--ink);color:#fff;border:none;border-radius:var(--r-sm);padding:11px 22px;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;transition:var(--tr)}
.suc-btn-new:hover{background:var(--rose)}
.suc-btn-cl{background:transparent;color:var(--mid);border:1.5px solid var(--border);border-radius:var(--r-sm);padding:11px 22px;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;transition:var(--tr)}
.suc-btn-cl:hover{border-color:var(--rose);color:var(--rose)}

/* ── TOAST ── */
.toast-zone{position:fixed;bottom:22px;left:50%;transform:translateX(-50%);z-index:700;display:flex;flex-direction:column;align-items:center;gap:7px;pointer-events:none}
.toast{background:var(--ink);color:#fff;padding:11px 22px;border-radius:28px;font-size:13.5px;font-weight:500;box-shadow:var(--sh-lg);animation:tIn .3s ease,tOut .4s ease 2.4s forwards;display:flex;align-items:center;gap:8px}
.toast.ok{background:var(--green)}.toast.err{background:var(--red)}
@keyframes tIn{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes tOut{to{opacity:0}}

/* ── RESPONSIVE ── */
@media(max-width:760px){
  .header{padding:0 14px;height:60px}
  .hdr-search{display:none}
  .logo-name{font-size:19px}
  .cat-bar{padding:0 14px;top:60px}
  .filter-row{padding:12px 14px}
  .main{padding:20px 14px 80px}
  .hero{height:300px}
  .hero h1{font-size:clamp(30px,8vw,48px)}
  .hero-stats{gap:18px}
  .items-grid{grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:13px}
  .ic-img{height:148px}
  .ic-name{font-size:15px}
  .modal{border-radius:20px 20px 0 0;max-height:95vh}
  .modal-bg{align-items:flex-end;padding:0}
  .m-img-wrap{height:170px}
  .m-body{padding:16px 16px}
  .price-bar{padding:12px 16px}
}
</style>
</head>
<body>

<!-- HEADER -->
<header class="header">
  <a class="logo" href="#">
    <div class="logo-badge">
      <img src="https://images.unsplash.com/photo-1578985545062-69928b1d9587?w=100&q=80" alt="CakeAtelier">
    </div>
    <div>
      <div class="logo-name">Cake<span>Atelier</span></div>
      <div class="logo-sub">Artisan Bakery</div>
    </div>
  </a>
  <div class="hdr-right">
    <div class="hdr-search">
      <span class="si">&#128269;</span>
      <input type="text" id="searchInput" placeholder="Search cakes, tarts, pastries...">
    </div>
    <div class="hdr-badge">
      Fresh Orders <span class="hbnum" id="orderCount">0</span>
    </div>
  </div>
</header>

<!-- HERO -->
<section class="hero">
  <div class="hero-bg"></div>
  <div class="hero-ov"></div>
  <div class="hero-cnt">
    <div class="hero-pill">Freshly Baked Every Day</div>
    <h1>Build Your<br><em>Dream Cake</em></h1>
    <p class="hero-sub">Click any item to customize — choose weight, ingredients, occasion and more. No limits.</p>
    <div class="hero-stats">
      <div class="hs"><div class="hs-n"><?= count($allItems) ?>+</div><div class="hs-l">Items</div></div>
      <div class="hs"><div class="hs-n"><?= count($categories) ?></div><div class="hs-l">Categories</div></div>
      <div class="hs"><div class="hs-n">Any</div><div class="hs-l">Weight</div></div>
      <div class="hs"><div class="hs-n">4.9</div><div class="hs-l">Rating</div></div>
    </div>
  </div>
</section>

<!-- TICKER -->
<div class="ticker-wrap">
  <div class="ticker">
    <?php
    $ticks = ['Free delivery above Rs.999','Eggless options available','Same day delivery','Custom cake messages','Gluten-free base available','Vegan options available','Sugar-free on request','100% natural ingredients','Order 2 days ahead for wedding cakes','Halal certified'];
    $ticks = array_merge($ticks,$ticks);
    foreach($ticks as $t): ?>
    <span class="ti"><?= htmlspecialchars($t) ?><span class="td"></span></span>
    <?php endforeach; ?>
  </div>
</div>

<!-- CATEGORY BAR -->
<div class="cat-bar">
  <div class="cat-bar-inner">
    <button class="cb active" data-slug="all" onclick="filterCat('all',this)">
      All <span class="cb-cnt"><?= count($allItems) ?></span>
    </button>
    <?php foreach($categories as $c):
      $cnt = count($itemsByCat[$c['slug']] ?? []);
      if(!$cnt) continue; ?>
    <button class="cb" data-slug="<?= $c['slug'] ?>" onclick="filterCat('<?= $c['slug'] ?>',this)">
      <?= htmlspecialchars($c['name']) ?> <span class="cb-cnt"><?= $cnt ?></span>
    </button>
    <?php endforeach; ?>
  </div>
</div>

<!-- DIET FILTER ROW -->
<div class="filter-row">
  <span class="filter-label">Filter by:</span>
  <div class="filter-chips" id="filterChips">
    <?php foreach($dietLabels as $dl): ?>
    <div class="fchip" data-did="<?= $dl['id'] ?>"
      style="--dl-color:<?= $dl['color'] ?>;--dl-bg:<?= $dl['bg'] ?>"
      onclick="toggleDietFilter(this)">
      <?= htmlspecialchars($dl['name']) ?>
    </div>
    <?php endforeach; ?>
  </div>
  <button class="filter-clear" onclick="clearFilters()">Clear All</button>
</div>

<!-- MAIN GRID -->
<main class="main" id="mainGrid">

  <?php foreach($categories as $c):
    $slug  = $c['slug'];
    $items = $itemsByCat[$slug] ?? [];
    if(empty($items)) continue;
    $isCupCat = isCupcakeCat($slug);
  ?>
  <div class="cat-section" id="cat-<?= $slug ?>" data-slug="<?= $slug ?>">
    <div class="section-head">
      <div class="sh-bar"></div>
      <div>
        <div class="sh-title"><?= htmlspecialchars($c['name']) ?></div>
        <div class="sh-count"><?= count($items) ?> items</div>
      </div>
      <div class="sh-line"></div>
    </div>
    <div class="items-grid">
      <?php foreach($items as $it):
        $dlIds = array_filter(explode(',', $it['diet_label_ids']));
        $dlIds = array_map('trim', $dlIds);
        $shownLabels = array_slice($dlIds, 0, 2);
        $displayImg  = getItemImage($it, $imageOverrides);
      ?>
      <div class="item-card"
        data-id="<?= $it['id'] ?>"
        data-slug="<?= $slug ?>"
        data-cupcake="<?= $isCupCat ? '1' : '0' ?>"
        data-search="<?= strtolower(htmlspecialchars($it['name'].' '.$it['description'].' '.$it['flavour_tags'])) ?>"
        data-labels="<?= htmlspecialchars($it['diet_label_ids']) ?>"
        onclick="openModal(<?= htmlspecialchars(json_encode(array_merge($it, ['_is_cupcake' => $isCupCat, '_display_img' => $displayImg]))) ?>)">

        <div class="ic-img">
          <img src="<?= htmlspecialchars($displayImg) ?>"
               alt="<?= htmlspecialchars($it['name']) ?>"
               loading="lazy"
               onerror="this.src='https://images.unsplash.com/photo-1578985545062-69928b1d9587?w=600&q=80'">
          <div class="ic-ov">
            <div class="ic-ov-btn">Customize & Order</div>
          </div>
          <div class="ic-badges">
            <?php if($it['is_bestseller']): ?><span class="ibadge ib-best">Best Seller</span><?php endif; ?>
            <?php if($isCupCat): ?>
              <span class="ibadge ib-piece">Per Piece</span>
            <?php else: ?>
              <span class="ibadge ib-veg"><?= $it['is_veg'] ? 'Veg' : 'Non-Veg' ?></span>
            <?php endif; ?>
          </div>
          <div class="ic-diet-labels">
            <?php foreach($shownLabels as $did):
              $dl = $dietMap[(int)$did] ?? null;
              if(!$dl) continue; ?>
            <span class="ic-dlabel"
              style="background:<?= $dl['bg'] ?>;color:<?= $dl['color'] ?>">
              <?= htmlspecialchars($dl['name']) ?>
            </span>
            <?php endforeach; ?>
          </div>
          <button class="ic-wish" onclick="event.stopPropagation();toggleWish(this)" title="Save to favourites">&#9825;</button>
        </div>

        <div class="ic-body">
          <div class="ic-meta">
            <span class="ic-cat"><?= htmlspecialchars($c['name']) ?></span>
            <span class="ic-rat">&#9733; 4.<?= rand(7,9) ?></span>
          </div>
          <div class="ic-name"><?= htmlspecialchars($it['name']) ?></div>
          <div class="ic-desc"><?= htmlspecialchars($it['description']) ?></div>
          <div class="ic-foot">
            <div class="ic-price">
              Rs.<?= number_format($it['base_price'],0) ?>
              <small><?= $isCupCat ? '/piece' : '/0.5kg' ?></small>
            </div>
            <button class="ic-add" onclick="event.stopPropagation();openModal(<?= htmlspecialchars(json_encode(array_merge($it, ['_is_cupcake' => $isCupCat, '_display_img' => $displayImg]))) ?>)">
              Customize
            </button>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <div class="no-results" id="noResults">
    <div class="nr-img">
      <img src="https://images.unsplash.com/photo-1464349095431-e9a21285b5f3?w=200&q=80" alt="No results">
    </div>
    <div class="nr-title">Nothing found</div>
    <div class="nr-sub">Try a different search or remove filters</div>
  </div>

</main>

<!-- ======================================================
     CUSTOMIZATION MODAL
     ====================================================== -->
<div class="modal-bg" id="modalBg" onclick="bgClick(event)">
<div class="modal" id="modal">

  <!-- Hero image -->
  <div class="m-img-wrap">
    <img id="m-img" src="" alt=""
         onerror="this.src='https://images.unsplash.com/photo-1578985545062-69928b1d9587?w=600&q=80'">
    <div class="m-img-ov">
      <div class="m-cat-lbl" id="m-cat"></div>
      <div class="m-name" id="m-name"></div>
      <div class="m-desc" id="m-desc"></div>
    </div>
    <div class="m-dlabels" id="m-dlabels"></div>
    <button class="m-close" onclick="closeModal()">&#10005;</button>
  </div>

  <!-- Scrollable body -->
  <div class="m-body">

    <!-- EGG PREFERENCE -->
    <div class="ms">
      <div class="ms-title">Egg Preference</div>
      <div class="egg-row">
        <div class="egg-opt sel-egg" id="egg-with" onclick="setEgg('with_egg')">
          <div class="eo-img">
            <img src="https://images.unsplash.com/photo-1578985545062-69928b1d9587?w=300&q=80" alt="With Egg">
          </div>
          <div class="eo-body">
            <div class="eo-lbl">With Egg</div>
            <div class="eo-sub">Classic, moist and fluffy</div>
          </div>
        </div>
        <div class="egg-opt" id="egg-without" onclick="setEgg('eggless')">
          <div class="eo-img">
            <img src="https://images.unsplash.com/photo-1464349095431-e9a21285b5f3?w=300&q=80" alt="Eggless">
          </div>
          <div class="eo-body">
            <div class="eo-lbl">Eggless</div>
            <div class="eo-sub">Equally delicious, veg-friendly</div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ CUPCAKE PIECES SELECTOR (shown only for cupcake category) ══ -->
    <div class="ms" id="cupcakePiecesSection">
      <div class="ms-title">
        Number of Pieces
        <small style="font-size:12px;font-weight:400;color:var(--muted)">(choose how many you want)</small>
      </div>

      <!-- Quick-pick presets -->
      <div class="pieces-presets" id="piecesPresets">
        <?php foreach([1,2,4,6,8,12,18,24] as $pp): ?>
        <div class="ppreset <?= $pp===1?'active':'' ?>" data-p="<?= $pp ?>" onclick="setPiecePreset(this)">
          <?= $pp ?>
          <small><?= $pp===1?'pc':($pp<4?'pcs':'pcs') ?></small>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Fine-tune stepper -->
      <div class="pieces-custom-row">
        <div class="pieces-ctrl">
          <button class="pcb" onclick="changePieces(-1)">&#8722;</button>
          <span class="pcv" id="piecesVal">1</span>
          <button class="pcb" onclick="changePieces(1)">+</button>
        </div>
        <span class="pieces-unit-label">pieces selected</span>
        <div class="pieces-subtotal">
          <span id="piecesSubtotal">Rs. 0</span>
          <small id="piecesSubtotalNote">@ Rs.0 / piece</small>
        </div>
      </div>

      <!-- Manual entry for any custom number -->
      <div class="pieces-manual-row">
        <label>Or enter any number of pieces</label>
        <input class="pinput" type="number" id="piecesInput" min="1" step="1" placeholder="e.g. 30"
          oninput="setPiecesFromInput(this.value)">
      </div>
    </div>

    <!-- ══ WEIGHT (shown for non-cupcake items) ══ -->
    <div class="ms" id="weightSection">
      <div class="ms-title">Cake Weight <small style="font-size:12px;font-weight:400;color:var(--muted)">(any quantity, no limit)</small></div>
      <div class="weight-presets" id="weightPresets">
        <?php foreach(['0.5','1','1.5','2','2.5','3','4','5'] as $wp): ?>
        <div class="wpreset <?= $wp==='0.5'?'active':'' ?>" data-w="<?= $wp ?>" onclick="setWeightPreset(this)"><?= $wp ?>kg</div>
        <?php endforeach; ?>
      </div>
      <div class="weight-custom-row">
        <label>Enter any weight (kg)</label>
        <input class="winput" type="number" id="weightInput" value="0.5" min="0.5" step="0.5">
      </div>
      <div class="serves-note" id="servesNote">Serves approximately 4–6 people</div>
    </div>

    <!-- DIET REQUIREMENTS -->
    <div class="ms">
      <div class="ms-title">Dietary Requirements</div>
      <div class="diet-chips" id="dietChips">
        <?php foreach($dietOptions as $opt): ?>
        <div class="dchip" onclick="this.classList.toggle('active')"><?= htmlspecialchars($opt) ?></div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- INGREDIENTS -->
    <div class="ms">
      <div class="ms-title">Customize Ingredients <small style="font-size:12px;font-weight:400;color:var(--muted)">(optional add-ons)</small></div>
      <?php foreach($ingByCat as $catKey=>$catIngs): ?>
      <div class="ing-acc" id="iacc-<?= $catKey ?>">
        <div class="ing-acc-head" onclick="toggleAcc('<?= $catKey ?>')">
          <div class="iah-thumb">
            <img src="<?= htmlspecialchars($catIngs[0]['image_url']) ?>" alt="" loading="lazy"
                 onerror="this.src='https://images.unsplash.com/photo-1578985545062-69928b1d9587?w=80&q=60'">
          </div>
          <span class="iah-lbl"><?= htmlspecialchars($catKey) ?></span>
          <span class="iah-cnt" id="iac-<?= $catKey ?>">0 added</span>
          <span class="iah-arr">&#9660;</span>
        </div>
        <div class="ing-list">
          <?php foreach($catIngs as $ing): ?>
          <div class="ing-item"
            id="ii-<?= $ing['id'] ?>"
            data-id="<?= $ing['id'] ?>"
            data-price="<?= $ing['unit_price'] ?>"
            data-unit="<?= htmlspecialchars($ing['unit']) ?>">
            <div class="ii-img">
              <img src="<?= htmlspecialchars($ing['image_url']) ?>"
                   alt="<?= htmlspecialchars($ing['name']) ?>" loading="lazy"
                   onerror="this.src='https://images.unsplash.com/photo-1578985545062-69928b1d9587?w=80&q=60'">
            </div>
            <div class="ii-info">
              <div class="ii-name"><?= htmlspecialchars($ing['name']) ?></div>
              <div class="ii-price">
                <?= $ing['unit_price']>0
                    ? 'Rs.'.number_format($ing['unit_price'],0).'/'.$ing['unit']
                    : 'Free' ?>
              </div>
            </div>
            <div class="ii-ctrl">
              <button class="qb" onclick="chIng(<?= $ing['id'] ?>,-1)">&#8722;</button>
              <span class="qv" id="qv-<?= $ing['id'] ?>">0</span>
              <button class="qb" onclick="chIng(<?= $ing['id'] ?>,1)">+</button>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- OCCASION & MESSAGE -->
    <div class="ms">
      <div class="ms-title">Occasion & Message</div>
      <div class="fg-grid" style="margin-bottom:12px">
        <div class="fg">
          <label>Occasion</label>
          <select id="mOccasion">
            <option value="">Select occasion</option>
            <?php foreach($occasions as $occ): ?>
            <option><?= htmlspecialchars($occ) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg">
          <label>Delivery Date</label>
          <input type="date" id="mDate" min="<?= date('Y-m-d',strtotime('+1 day')) ?>">
        </div>
      </div>
      <div class="msg-wrap">
        <textarea id="mMessage" rows="2" maxlength="100"
          placeholder="Message on the cake — e.g. Happy Birthday Priya!"
          oninput="document.getElementById('mMsgCnt').textContent=this.value.length"></textarea>
        <span class="msg-cnt"><span id="mMsgCnt">0</span>/100</span>
      </div>
    </div>

    <!-- CUSTOMER DETAILS -->
    <div class="ms">
      <div class="ms-title">Your Details</div>
      <div class="fg-grid">
        <div class="fg">
          <label>Full Name *</label>
          <input type="text" id="mName" placeholder="Your name">
          <span class="ferr" id="ferr-name">Name is required</span>
        </div>
        <div class="fg">
          <label>Phone *</label>
          <input type="tel" id="mPhone" placeholder="+91 98765 43210">
          <span class="ferr" id="ferr-phone">Phone is required</span>
        </div>
        <div class="fg">
          <label>Email</label>
          <input type="email" id="mEmail" placeholder="you@email.com">
        </div>
        <div class="fg">
          <label>Delivery Time</label>
          <select id="mTime">
            <option value="">Choose time</option>
            <option>Morning (9am - 12pm)</option>
            <option>Afternoon (12pm - 3pm)</option>
            <option>Evening (3pm - 6pm)</option>
            <option>Night (6pm - 9pm)</option>
          </select>
        </div>
        <div class="fg full">
          <label>Delivery Address *</label>
          <input type="text" id="mAddr" placeholder="Full address with street, city and pincode">
          <span class="ferr" id="ferr-addr">Address is required</span>
        </div>
        <div class="fg full">
          <label>Special Notes for Baker</label>
          <textarea id="mNotes" rows="2" placeholder="Allergies, design preferences, dietary needs..."></textarea>
        </div>
      </div>
    </div>

  </div><!-- .m-body -->

  <!-- STICKY PRICE BAR -->
  <div class="price-bar">
    <div class="pb-price">
      <span class="pb-lbl">Total Price</span>
      <span class="pb-val" id="pbVal">Rs. 0</span>
      <span class="pb-break" id="pbBreak">Select quantity to see price</span>
    </div>
    <button class="pb-btn" id="pbBtn" onclick="placeOrder()">
      <span>Place Order</span>
      <div class="spinner" id="pbSpinner"></div>
    </button>
  </div>

</div><!-- .modal -->
</div><!-- .modal-bg -->

<!-- SUCCESS OVERLAY -->
<div class="suc-overlay" id="sucOverlay">
  <div class="suc-card">
    <div class="suc-img">
      <img src="https://images.unsplash.com/photo-1519915028121-7d3463d20b13?w=600&q=80" alt="Order Success">
    </div>
    <div class="suc-body">
      <div class="suc-icon">&#127881;</div>
      <div class="suc-title">Order Placed!</div>
      <div class="suc-ref">Reference: <b id="suc-ref">—</b></div>
      <div class="suc-total" id="suc-total">Rs. 0</div>
      <div class="suc-msg">Our bakers have received your order. We will call you within 30 minutes to confirm details and delivery time.</div>
      <div class="suc-btns">
        <button class="suc-btn-new" onclick="newOrder()">Order Another Cake</button>
        <button class="suc-btn-cl" onclick="closeSuc()">Close</button>
      </div>
    </div>
  </div>
</div>

<div class="toast-zone" id="toastZone"></div>

<!-- ======================================================
     JAVASCRIPT
     ====================================================== -->
<script>
// ── STATE ─────────────────────────────────────────────────────
let curItem     = null;
let isCupcake   = false;   // true when current item is a cupcake
let pieces      = 1;       // cupcake piece count
let eggPref     = 'with_egg';
let weightKg    = 0.5;
let ingredients = {};
let orderCount  = 0;
let activeCat   = 'all';
let activeDietFilters = new Set();

const SERVES = {0.5:'4-6',1:'8-12',1.5:'14-18',2:'20-25',2.5:'26-32',3:'33-40',4:'48-56',5:'60-70',6:'72-84',8:'96-108',10:'120+'};
// Preset piece counts shown in modal
const PIECE_PRESETS = [1,2,4,6,8,12,18,24];

// ── TOAST ─────────────────────────────────────────────────────
function toast(msg, type=''){
  const z = document.getElementById('toastZone');
  const t = document.createElement('div');
  t.className = 'toast ' + type;
  t.textContent = msg;
  z.appendChild(t);
  setTimeout(() => t.remove(), 2900);
}

// ── WISH ──────────────────────────────────────────────────────
function toggleWish(btn){
  const liked = btn.classList.toggle('liked');
  btn.innerHTML = liked ? '&#10084;' : '&#9825;';
  btn.style.color = liked ? 'var(--rose)' : '';
  toast(liked ? 'Added to favourites' : 'Removed from favourites');
}

// ── CATEGORY FILTER ───────────────────────────────────────────
function filterCat(slug, btn){
  document.querySelectorAll('.cb').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  activeCat = slug;
  applyFilters();
}

// ── DIET FILTER ───────────────────────────────────────────────
function toggleDietFilter(chip){
  const did = chip.dataset.did;
  chip.classList.toggle('active');
  if(activeDietFilters.has(did)) activeDietFilters.delete(did);
  else activeDietFilters.add(did);
  applyFilters();
}
function clearFilters(){
  activeDietFilters.clear();
  document.querySelectorAll('.fchip').forEach(c => c.classList.remove('active'));
  applyFilters();
}

// ── APPLY FILTERS ─────────────────────────────────────────────
function applyFilters(){
  const q   = document.getElementById('searchInput').value.toLowerCase().trim();
  let total = 0;
  document.querySelectorAll('.cat-section').forEach(sec => {
    const secSlug = sec.dataset.slug;
    if(activeCat !== 'all' && activeCat !== secSlug){ sec.style.display='none'; return; }
    const cards = sec.querySelectorAll('.item-card');
    let secVis = false;
    cards.forEach(card => {
      let show = true;
      if(q && !card.dataset.search.includes(q)) show = false;
      if(show && activeDietFilters.size > 0){
        const lbls = (card.dataset.labels||'').split(',').map(s=>s.trim());
        for(const did of activeDietFilters){
          if(!lbls.includes(did)){ show = false; break; }
        }
      }
      card.style.display = show ? '' : 'none';
      if(show){ secVis = true; total++; }
    });
    sec.style.display = secVis ? '' : 'none';
  });
  document.getElementById('noResults').style.display = total === 0 ? 'block' : 'none';
}

document.getElementById('searchInput').addEventListener('input', applyFilters);

// ── CARD SCROLL REVEAL ────────────────────────────────────────
const revealObs = new IntersectionObserver(entries => {
  entries.forEach(e => {
    if(e.isIntersecting){ e.target.classList.add('in-view'); revealObs.unobserve(e.target); }
  });
}, {threshold:0.08});
document.querySelectorAll('.item-card').forEach(c => revealObs.observe(c));

// ── MODAL OPEN ────────────────────────────────────────────────
function openModal(item){
  curItem     = item;
  isCupcake   = !!item._is_cupcake;
  eggPref     = 'with_egg';
  weightKg    = 0.5;
  pieces      = 1;
  ingredients = {};

  // Populate header
  document.getElementById('m-img').src         = item._display_img || item.image_url;
  document.getElementById('m-cat').textContent  = item.cat_name || '';
  document.getElementById('m-name').textContent = item.name;
  document.getElementById('m-desc').textContent = item.description;

  // Diet labels in modal
  const dlWrap = document.getElementById('m-dlabels');
  dlWrap.innerHTML = '';
  const dlData = <?= json_encode($dietMap) ?>;
  const dlIds  = (item.diet_label_ids||'').split(',').map(s=>s.trim()).filter(Boolean);
  dlIds.forEach(did => {
    const dl = dlData[did];
    if(!dl) return;
    const sp = document.createElement('span');
    sp.className = 'm-dl';
    sp.textContent = dl.name;
    sp.style.background = dl.bg;
    sp.style.color = dl.color;
    dlWrap.appendChild(sp);
  });

  // Reset egg
  document.getElementById('egg-with').className    = 'egg-opt sel-egg';
  document.getElementById('egg-without').className = 'egg-opt';

  // ── Show / hide correct quantity section ──────────────────
  if(isCupcake){
    document.getElementById('cupcakePiecesSection').style.display = 'block';
    document.getElementById('weightSection').style.display        = 'none';
    // Reset pieces UI
    document.getElementById('piecesVal').textContent = '1';
    document.getElementById('piecesInput').value = '';
    document.querySelectorAll('.ppreset').forEach(p => p.classList.toggle('active', p.dataset.p === '1'));
    updatePiecesUI();
  } else {
    document.getElementById('cupcakePiecesSection').style.display = 'none';
    document.getElementById('weightSection').style.display = 'block';
    // Reset weight
    document.getElementById('weightInput').value = '0.5';
    document.querySelectorAll('.wpreset').forEach(p => p.classList.toggle('active', p.dataset.w==='0.5'));
    document.getElementById('servesNote').textContent = 'Serves approximately 4-6 people';
  }

  // Reset ingredient qtys
  document.querySelectorAll('[id^="qv-"]').forEach(el => el.textContent='0');
  document.querySelectorAll('.ing-item').forEach(el => el.classList.remove('has-qty'));
  document.querySelectorAll('[id^="iac-"]').forEach(el => el.textContent='0 added');

  // Reset diet chips
  document.querySelectorAll('#dietChips .dchip').forEach(c => c.classList.remove('active'));

  // Reset form
  ['mName','mPhone','mEmail','mAddr','mMessage','mNotes'].forEach(id=>{
    const el=document.getElementById(id); if(el) el.value='';
  });
  document.getElementById('mOccasion').value='';
  document.getElementById('mTime').value='';
  document.getElementById('mDate').value='';
  document.getElementById('mMsgCnt').textContent='0';
  ['ferr-name','ferr-phone','ferr-addr'].forEach(id=>document.getElementById(id).classList.remove('show'));

  recalcPrice();

  // Open first two ingredient accordions
  const firstTwo = <?= json_encode(array_slice(array_keys($ingByCat), 0, 2)) ?>;
  document.querySelectorAll('.ing-acc').forEach(a => a.classList.remove('open'));
  firstTwo.forEach(k => document.getElementById('iacc-'+k)?.classList.add('open'));

  const bg = document.getElementById('modalBg');
  bg.classList.add('open');
  bg.classList.remove('closing');
  document.body.style.overflow = 'hidden';
  document.querySelector('.m-body').scrollTop = 0;
}

function bgClick(e){ if(e.target===document.getElementById('modalBg')) closeModal(); }
function closeModal(){
  const bg = document.getElementById('modalBg');
  bg.classList.add('closing');
  setTimeout(()=>{ bg.classList.remove('open','closing'); document.body.style.overflow=''; }, 270);
}
document.addEventListener('keydown', e=>{ if(e.key==='Escape') closeModal(); });

// ── EGG ───────────────────────────────────────────────────────
function setEgg(v){
  eggPref = v;
  document.getElementById('egg-with').className    = 'egg-opt'+(v==='with_egg'?' sel-egg':'');
  document.getElementById('egg-without').className = 'egg-opt'+(v==='eggless'?' sel-eggless':'');
}

// ── CUPCAKE PIECES ────────────────────────────────────────────
function setPiecePreset(el){
  document.querySelectorAll('.ppreset').forEach(p=>p.classList.remove('active'));
  el.classList.add('active');
  pieces = parseInt(el.dataset.p);
  document.getElementById('piecesInput').value = pieces;
  document.getElementById('piecesVal').textContent = pieces;
  updatePiecesUI();
  recalcPrice();
}

function changePieces(delta){
  pieces = Math.max(1, pieces + delta);
  document.getElementById('piecesVal').textContent = pieces;
  document.getElementById('piecesInput').value = pieces;
  // Sync presets
  document.querySelectorAll('.ppreset').forEach(p=>{
    p.classList.toggle('active', parseInt(p.dataset.p) === pieces);
  });
  updatePiecesUI();
  recalcPrice();
}

function setPiecesFromInput(val){
  const n = Math.max(1, parseInt(val)||1);
  pieces = n;
  document.getElementById("piecesVal").textContent = n;
  document.querySelectorAll(".ppreset").forEach(p=>{
    p.classList.toggle("active", parseInt(p.dataset.p) === n);
  });
  updatePiecesUI();
  recalcPrice();
}

function updatePiecesUI(){
  if(!curItem) return;
  const pricePerPiece = parseFloat(curItem.base_price) || 0;
  const subtotal      = Math.round(pricePerPiece * pieces);
  document.getElementById('piecesSubtotal').textContent    = 'Rs. ' + subtotal.toLocaleString('en-IN');
  document.getElementById('piecesSubtotalNote').textContent = '@ Rs.' + Math.round(pricePerPiece) + ' / piece';
}

// ── WEIGHT ────────────────────────────────────────────────────
function setWeightPreset(el){
  document.querySelectorAll('.wpreset').forEach(p=>p.classList.remove('active'));
  el.classList.add('active');
  const w = parseFloat(el.dataset.w);
  document.getElementById('weightInput').value = w;
  weightKg = w;
  updateServes(w);
  recalcPrice();
}
document.getElementById('weightInput').addEventListener('input', function(){
  const w = Math.max(0.5, parseFloat(this.value)||0.5);
  weightKg = w;
  document.querySelectorAll('.wpreset').forEach(p=>p.classList.remove('active'));
  updateServes(w);
  recalcPrice();
});
function updateServes(w){
  const key = Math.round(w*2)/2;
  const srv = SERVES[key] || Math.round(w*10)+'-'+Math.round(w*13);
  document.getElementById('servesNote').textContent = 'Serves approximately '+srv+' people';
}

// ── INGREDIENT ACCORDION ──────────────────────────────────────
function toggleAcc(key){
  document.getElementById('iacc-'+key).classList.toggle('open');
}

// ── INGREDIENT QTY ────────────────────────────────────────────
function chIng(id, delta){
  const el  = document.getElementById('qv-'+id);
  const row = document.getElementById('ii-'+id);
  let cur   = parseInt(el.textContent)||0;
  cur       = Math.max(0, cur+delta);
  el.textContent = cur;
  if(cur>0){ ingredients[id]=cur; row.classList.add('has-qty'); }
  else      { delete ingredients[id]; row.classList.remove('has-qty'); }
  const acc = row.closest('.ing-acc');
  if(acc){
    const key = acc.id.replace('iacc-','');
    const cnt = acc.querySelectorAll('.ing-item.has-qty').length;
    const c   = document.getElementById('iac-'+key);
    if(c) c.textContent = cnt + ' added';
  }
  recalcPrice();
}

// ── PRICE RECALC ─────────────────────────────────────────────
function recalcPrice(){
  if(!curItem) return;
  let baseP = 0;
  if(isCupcake){
    baseP = parseFloat(curItem.base_price) * pieces;
  } else {
    const units = weightKg / 0.5;
    baseP = parseFloat(curItem.base_price) * units;
  }
  let ingT = 0;
  Object.entries(ingredients).forEach(([id,qty])=>{
    const r = document.getElementById('ii-'+id);
    if(r) ingT += qty * parseFloat(r.dataset.price||0);
  });
  const total = baseP + ingT;
  document.getElementById('pbVal').textContent =
    'Rs. ' + Math.round(total).toLocaleString('en-IN');

  if(isCupcake){
    document.getElementById('pbBreak').textContent =
      pieces + ' piece' + (pieces>1?'s':'') +
      '  ×  Rs.' + Math.round(parseFloat(curItem.base_price)) +
      (ingT>0 ? '  +  Add-ons Rs.'+Math.round(ingT) : '');
  } else {
    document.getElementById('pbBreak').textContent =
      'Base Rs.'+Math.round(baseP) +
      (ingT>0 ? '  +  Add-ons Rs.'+Math.round(ingT) : '') +
      '  |  '+weightKg+'kg';
  }

  // Also refresh pieces sub-total display when not cupcake
  if(isCupcake) updatePiecesUI();
}

// ── PLACE ORDER ───────────────────────────────────────────────
async function placeOrder(){
  const name  = document.getElementById('mName').value.trim();
  const phone = document.getElementById('mPhone').value.trim();
  const addr  = document.getElementById('mAddr').value.trim();
  let ok = true;
  const show = (id,v)=>{ document.getElementById(id).classList.toggle('show',v); if(v) ok=false; };
  show('ferr-name',!name); show('ferr-phone',!phone); show('ferr-addr',!addr);
  if(!ok){ toast('Please fill all required fields','err'); return; }

  if(!isCupcake){
    weightKg = Math.max(0.5, parseFloat(document.getElementById('weightInput').value)||0.5);
  }

  const dietReqs = [...document.querySelectorAll('#dietChips .dchip.active')]
                   .map(c=>c.textContent.trim()).join(', ');

  const ingArr = Object.entries(ingredients).map(([id,qty])=>({id:parseInt(id),qty}));
  const fd = new FormData();
  fd.append('action','place_order');
  fd.append('item_id',curItem.id);
  fd.append('is_cupcake', isCupcake ? '1' : '0');
  fd.append('pieces', pieces);
  fd.append('customer_name',name);
  fd.append('customer_phone',phone);
  fd.append('customer_email',document.getElementById('mEmail').value.trim());
  fd.append('delivery_address',addr);
  fd.append('delivery_date',document.getElementById('mDate').value);
  fd.append('delivery_time',document.getElementById('mTime').value);
  fd.append('egg_pref',eggPref);
  fd.append('weight_kg', isCupcake ? 0 : weightKg);
  fd.append('cake_message',document.getElementById('mMessage').value.trim());
  fd.append('occasion',document.getElementById('mOccasion').value);
  fd.append('diet_requirements',dietReqs);
  fd.append('special_notes',document.getElementById('mNotes').value.trim());
  fd.append('ingredients',JSON.stringify(ingArr));

  const btn = document.getElementById('pbBtn');
  const sp  = document.getElementById('pbSpinner');
  btn.disabled=true; sp.style.display='block';
  btn.querySelector('span').textContent='Placing...';

  try {
    const res = await fetch(location.href, {method:"POST", body:fd});
    let data;
    try {
      data = await res.json();
    } catch(parseErr) {
      const raw = await res.clone().text().catch(()=>"(unreadable)");
      toast("Server returned invalid response. Check PHP logs.", "err");
      console.error("Raw server response:", raw);
      return;
    }
    if(data.success){
      closeModal();
      setTimeout(()=>{
        document.getElementById("suc-ref").textContent   = data.order_ref;
        document.getElementById("suc-total").textContent =
          "Rs. "+Math.round(data.total).toLocaleString("en-IN");
        document.getElementById("sucOverlay").classList.add("show");
        orderCount++;
        document.getElementById("orderCount").textContent = orderCount;
      }, 340);
    } else {
      toast(data.message||"Something went wrong","err");
    }
  } catch(e){
    toast("Could not reach server: "+e.message, "err");
    console.error("Fetch error:", e);
  } finally {
    btn.disabled=false; sp.style.display="none";
    btn.querySelector("span").textContent="Place Order";
  }
}

// ── SUCCESS ───────────────────────────────────────────────────
function closeSuc(){  document.getElementById('sucOverlay').classList.remove('show'); }
function newOrder(){
  closeSuc();
  window.scrollTo({top:0,behavior:'smooth'});
}
</script>
</body>
</html>