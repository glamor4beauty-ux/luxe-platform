<?php
/* ═══════════════════════════════════════════════════════════════════════════
   write.php — everything this app can change.

   Three rules run through all of it.

   Shopify reports most failures in userErrors alongside HTTP 200, so a request
   can look like it worked and have changed nothing. Anything in userErrors is
   treated as a failure.

   Money moves are confirmed by typing, not tapping. A refund cannot be undone
   and neither can a cancellation, so those need $confirm to match a phrase the
   interface makes the person type out.

   Nothing is guessed. Where a field was not sent, it is left alone rather than
   blanked — an absent key and an empty string mean different things.

     Products    product, variant, stock
     Customers   customer, customer_create, customer_address
     Orders      order, order_cancel, order_note
     Payments    order_capture, order_paid, refund
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/lib.php';
require_once __DIR__ . '/authz.php';

header('Content-Type: application/json; charset=utf-8');

auth_start();
if (!auth_user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not signed in']);
    exit;
}
if (!can_write()) {
    http_response_code(403);
    echo json_encode(['ok' => false,
        'error' => 'Your account is read only. Ask the superadmin if you need to change things.']);
    exit;
}

function out(array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(['ok' => false, 'error' => 'POST only'], 405);

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];

function gid(string $type, $n): string {
    $n = (string)$n;
    return str_starts_with($n, 'gid://') ? $n : 'gid://shopify/' . $type . '/' . preg_replace('/\D/', '', $n);
}

function errs(array $data, string $root): array {
    $out = [];
    foreach ($data[$root]['userErrors'] ?? [] as $x) {
        $f = !empty($x['field']) ? implode('.', (array)$x['field']) . ': ' : '';
        $out[] = $f . ($x['message'] ?? 'unknown error');
    }
    return $out;
}

/* Given but empty means clear it; absent means leave it. */
function given(array $in, string $key, bool $nullIfBlank = true) {
    if (!array_key_exists($key, $in)) return '__skip__';
    $v = is_string($in[$key]) ? trim($in[$key]) : $in[$key];
    if ($v === '' && $nullIfBlank) return null;
    return $v;
}

/* Irreversible things get typed out in full, so a mis-tap cannot do them. */
function require_typed(array $in, string $phrase): void {
    $said = strtoupper(trim((string)($in['confirm'] ?? '')));
    if ($said !== strtoupper($phrase)) {
        out(['ok' => false, 'needs_confirm' => $phrase,
             'error' => 'Type ' . $phrase . ' to confirm. This cannot be undone.'], 400);
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
   METAFIELDS — dropship cost and MPN

   Shopify has no field for what a supplier charges you, nor for a
   manufacturer part number, so both are metafields. Defining them once means
   they appear in Shopify's own admin too, rather than existing only here and
   confusing whoever opens the product there.

   Cost is deliberately separate from Shopify's own unitCost: that one is what
   the item cost you, while this is what Trendsi charges to ship it. Adding
   them together is a decision for later, not something to bury in a field.
   ═══════════════════════════════════════════════════════════════════════════ */

const MF_NAMESPACE = 'trendsi';
const MF_FIELDS = [
    'dropship_cost' => [
        'name' => 'Trendsi dropshipping cost',
        'type' => 'number_decimal',
        'desc' => 'What Trendsi charges to ship this item. Separate from the item cost.',
    ],
    'mpn' => [
        'name' => 'MPN',
        'type' => 'single_line_text_field',
        'desc' => 'Manufacturer part number, as given by the supplier.',
    ],
];

$action = $_GET['action'] ?? '';

switch ($action) {

/* ═══════════════════════════════════════════════════════════════════════════
   PRODUCTS
   ═══════════════════════════════════════════════════════════════════════════ */

case 'product': {
    $id = trim((string)($in['id'] ?? ''));
    if ($id === '') out(['ok' => false, 'error' => 'id required'], 400);

    $changed = [];
    $problems = [];

    /* ── the product ── */
    $pIn = ['id' => gid('Product', $id)];
    foreach ([['title', 'title'], ['description', 'descriptionHtml'],
              ['vendor', 'vendor'], ['type', 'productType']] as [$k, $f]) {
        $v = given($in, $k, $k !== 'description');
        if ($v === '__skip__') continue;
        if ($k === 'title' && ($v === null || $v === '')) {
            out(['ok' => false, 'error' => 'A product needs a title'], 400);
        }
        $pIn[$f] = $v;
    }
    if (array_key_exists('tags', $in)) {
        $t = $in['tags'];
        $pIn['tags'] = is_array($t)
            ? array_values(array_filter(array_map('trim', $t)))
            : array_values(array_filter(array_map('trim', explode(',', (string)$t))));
    }
    if (array_key_exists('status', $in)) {
        $st = strtoupper(trim((string)$in['status']));
        if (!in_array($st, ['ACTIVE', 'DRAFT', 'ARCHIVED'], true)) {
            out(['ok' => false, 'error' => 'Status must be active, draft or archived'], 400);
        }
        $pIn['status'] = $st;
    }

    if (count($pIn) > 1) {
        /* the argument name changed between API versions; try the current
           shape, fall back rather than failing on a rename */
        $r = shop_query('
            mutation($product: ProductUpdateInput!) {
              productUpdate(product: $product) {
                product { id title status vendor productType tags }
                userErrors { field message }
              }
            }', ['product' => $pIn]);
        if (!$r['ok'] && stripos($r['message'] ?? '', 'ProductUpdateInput') !== false) {
            $r = shop_query('
                mutation($input: ProductInput!) {
                  productUpdate(input: $input) {
                    product { id title status vendor productType tags }
                    userErrors { field message }
                  }
                }', ['input' => $pIn]);
        }
        if (!$r['ok']) $problems[] = $r['message'];
        else {
            $e = errs($r['data'], 'productUpdate');
            if ($e) $problems[] = implode('; ', $e);
            else $changed['product'] = $r['data']['productUpdate']['product'] ?? null;
        }
    }

    /* ── the variant: price, compare-at, sku, barcode, weight ── */
    $vid = trim((string)($in['variant_id'] ?? ''));
    $vFields = ['price', 'compare_at', 'sku', 'barcode', 'weight'];
    $touchesVariant = false;
    foreach ($vFields as $f) if (array_key_exists($f, $in)) $touchesVariant = true;

    if ($vid !== '' && $touchesVariant) {
        $v = ['id' => gid('ProductVariant', $vid)];
        $item = [];

        foreach ([['price', 'price'], ['compare_at', 'compareAtPrice']] as [$k, $f]) {
            $x = given($in, $k);
            if ($x === '__skip__') continue;
            if ($x !== null && !is_numeric($x)) {
                out(['ok' => false, 'error' => ucfirst(str_replace('_', ' ', $k)) . ' must be a number'], 400);
            }
            $v[$f] = $x;
        }
        foreach ([['sku', 'sku'], ['barcode', 'barcode']] as [$k, $f]) {
            $x = given($in, $k, false);
            if ($x === '__skip__') continue;
            if ($f === 'sku') $item['sku'] = (string)$x;
            else $v['barcode'] = (string)$x;
        }
        if (array_key_exists('weight', $in)) {
            $w = given($in, 'weight');
            if ($w !== '__skip__' && $w !== null) {
                if (!is_numeric($w)) out(['ok' => false, 'error' => 'Weight must be a number'], 400);
                $item['measurement'] = ['weight' => [
                    'value' => (float)$w,
                    'unit'  => strtoupper(trim((string)($in['weight_unit'] ?? 'POUNDS'))),
                ]];
            }
        }
        if ($item) $v['inventoryItem'] = $item;

        $r2 = shop_query('
            mutation($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
              productVariantsBulkUpdate(productId: $productId, variants: $variants) {
                productVariants { id sku price compareAtPrice barcode }
                userErrors { field message }
              }
            }', ['productId' => gid('Product', $id), 'variants' => [$v]]);

        if (!$r2['ok']) $problems[] = $r2['message'];
        else {
            $e = errs($r2['data'], 'productVariantsBulkUpdate');
            if ($e) $problems[] = implode('; ', $e);
            else $changed['variant'] = $r2['data']['productVariantsBulkUpdate']['productVariants'][0] ?? null;
        }
    }

    /* ── cost, which lives on the inventory item ── */
    if (array_key_exists('cost', $in) && $vid !== '') {
        $c = given($in, 'cost');
        if ($c !== '__skip__') {
            if ($c !== null && !is_numeric($c)) out(['ok' => false, 'error' => 'Cost must be a number'], 400);

            $look = shop_query('query($id: ID!) { productVariant(id: $id) { inventoryItem { id } } }',
                               ['id' => gid('ProductVariant', $vid)]);
            $iid = $look['ok'] ? ($look['data']['productVariant']['inventoryItem']['id'] ?? '') : '';

            if ($iid === '') $problems[] = 'Could not find the inventory item to set a cost on.';
            else {
                $r3 = shop_query('
                    mutation($id: ID!, $input: InventoryItemInput!) {
                      inventoryItemUpdate(id: $id, input: $input) {
                        inventoryItem { id unitCost { amount currencyCode } }
                        userErrors { field message }
                      }
                    }', ['id' => $iid, 'input' => ['cost' => $c]]);
                if (!$r3['ok']) $problems[] = $r3['message'];
                else {
                    $e = errs($r3['data'], 'inventoryItemUpdate');
                    if ($e) $problems[] = implode('; ', $e);
                    else $changed['cost'] = $r3['data']['inventoryItemUpdate']['inventoryItem']['unitCost'] ?? null;
                }
            }
        }
    }

    if (!$changed && !$problems) out(['ok' => false, 'error' => 'Nothing to change'], 400);
    if (!$changed) out(['ok' => false, 'error' => implode(' · ', $problems)], 400);

    /* half a change landing is worth saying out loud */
    out(['ok' => true, 'changed' => $changed,
         'partial' => $problems ? implode(' · ', $problems) : null]);
}

/* ═══ apply cost to every variant ══════════════════════════════════════════
   Cost belongs to the variant, not the product, so a product with 25 sizes has
   25 costs. When they are all the same — which for a dropshipped line they
   usually are — editing them one at a time is 25 chances to fumble one.

   This overwrites every variant, including any that had been set differently
   on purpose. The interface says so before it runs, because that is not
   recoverable. */
case 'cost_all': {
    $id = trim((string)($in['id'] ?? ''));
    if ($id === '') out(['ok' => false, 'error' => 'id required'], 400);

    $cost = trim((string)($in['cost'] ?? ''));
    $ship = array_key_exists('dropship_cost', $in) ? trim((string)$in['dropship_cost']) : null;

    if ($cost === '' && $ship === null) out(['ok' => false, 'error' => 'Nothing to apply'], 400);
    if ($cost !== '' && !is_numeric($cost)) out(['ok' => false, 'error' => 'Cost must be a number'], 400);
    if ($ship !== null && $ship !== '' && !is_numeric($ship)) {
        out(['ok' => false, 'error' => 'Dropshipping cost must be a number'], 400);
    }

    /* every variant, so none is missed */
    $look = shop_query('
        query($id: ID!) {
          product(id: $id) {
            title
            variants(first: 100) { edges { node { id title } } }
          }
        }', ['id' => gid('Product', $id)]);
    if (!$look['ok']) out(['ok' => false, 'error' => $look['message']], 400);

    $variants = $look['data']['product']['variants']['edges'] ?? [];
    if (!$variants) out(['ok' => false, 'error' => 'This product has no variants'], 400);

    $changed = [];
    $problems = [];

    /* ── cost, in one call rather than one per variant ── */
    if ($cost !== '') {
        $payload = [];
        foreach ($variants as $ve) {
            $payload[] = ['id' => $ve['node']['id'], 'inventoryItem' => ['cost' => $cost]];
        }
        $r = shop_query('
            mutation($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
              productVariantsBulkUpdate(productId: $productId, variants: $variants) {
                productVariants { id }
                userErrors { field message }
              }
            }', ['productId' => gid('Product', $id), 'variants' => $payload]);

        if (!$r['ok']) $problems[] = $r['message'];
        else {
            $e = errs($r['data'], 'productVariantsBulkUpdate');
            if ($e) $problems[] = implode('; ', $e);
            else $changed['cost'] = count($r['data']['productVariantsBulkUpdate']['productVariants'] ?? []);
        }
    }

    /* ── shipping: set on the product, and cleared from the variants so none
          of them quietly keeps an old figure that overrides it ── */
    if ($ship !== null) {
        if ($ship === '') {
            $mf = [['ownerId' => gid('Product', $id), 'namespace' => MF_NAMESPACE,
                    'key' => 'dropship_cost']];
            foreach ($variants as $ve) {
                $mf[] = ['ownerId' => $ve['node']['id'], 'namespace' => MF_NAMESPACE,
                         'key' => 'dropship_cost'];
            }
            $r2 = shop_query('
                mutation($metafields: [MetafieldIdentifierInput!]!) {
                  metafieldsDelete(metafields: $metafields) {
                    deletedMetafields { key }
                    userErrors { field message }
                  }
                }', ['metafields' => $mf]);
            if ($r2['ok']) $changed['dropship_cleared'] = true;
        } else {
            $r2 = shop_query('
                mutation($metafields: [MetafieldsSetInput!]!) {
                  metafieldsSet(metafields: $metafields) {
                    metafields { key value }
                    userErrors { field message }
                  }
                }', ['metafields' => [[
                    'ownerId' => gid('Product', $id), 'namespace' => MF_NAMESPACE,
                    'key' => 'dropship_cost', 'type' => 'number_decimal', 'value' => $ship,
                ]]]);

            if (!$r2['ok']) $problems[] = $r2['message'];
            else {
                $e = errs($r2['data'], 'metafieldsSet');
                if ($e) $problems[] = implode('; ', $e);
                else {
                    $changed['dropship'] = $ship;

                    /* A variant with its own figure would override the product
                       one, so clearing them is what makes "apply to all"
                       actually mean all. */
                    $del = [];
                    foreach ($variants as $ve) {
                        $del[] = ['ownerId' => $ve['node']['id'], 'namespace' => MF_NAMESPACE,
                                  'key' => 'dropship_cost'];
                    }
                    shop_query('
                        mutation($metafields: [MetafieldIdentifierInput!]!) {
                          metafieldsDelete(metafields: $metafields) {
                            deletedMetafields { key }
                            userErrors { field message }
                          }
                        }', ['metafields' => $del]);
                    $changed['overrides_cleared'] = count($del);
                }
            }
        }
    }

    if (!$changed) out(['ok' => false, 'error' => implode(' · ', $problems) ?: 'Nothing changed'], 400);

    out(['ok' => true, 'variants' => count($variants), 'changed' => $changed,
         'partial' => $problems ? implode(' · ', $problems) : null]);
}

/* ═══ stock at a location ══════════════════════════════════════════════════
   An absolute count, not a delta: a delta applied twice is wrong twice, and a
   count is what is actually in front of you. */
case 'stock': {
    $item = trim((string)($in['inventory_item_id'] ?? ''));
    $loc  = trim((string)($in['location_id'] ?? ''));
    $vid  = trim((string)($in['variant_id'] ?? ''));

    if ($item === '' && $vid !== '') {
        $look = shop_query('query($id: ID!) { productVariant(id: $id) { inventoryItem { id } } }',
                           ['id' => gid('ProductVariant', $vid)]);
        $item = $look['ok'] ? gid_num($look['data']['productVariant']['inventoryItem']['id'] ?? '') : '';
    }
    if ($item === '' || $loc === '') out(['ok' => false, 'error' => 'item and location required'], 400);
    if (!array_key_exists('quantity', $in)) out(['ok' => false, 'error' => 'quantity required'], 400);

    $qty = (int)$in['quantity'];
    if ($qty < 0) out(['ok' => false, 'error' => 'Quantity cannot be negative'], 400);

    $r = shop_query('
        mutation($input: InventorySetQuantitiesInput!) {
          inventorySetQuantities(input: $input) {
            inventoryAdjustmentGroup { changes { name delta quantityAfterChange } }
            userErrors { field message }
          }
        }', ['input' => [
            'name' => 'available', 'reason' => 'correction', 'ignoreCompareQuantity' => true,
            'quantities' => [[
                'inventoryItemId' => gid('InventoryItem', $item),
                'locationId'      => gid('Location', $loc),
                'quantity'        => $qty,
            ]],
        ]]);

    if (!$r['ok']) out(['ok' => false, 'error' => $r['message']], 400);
    $e = errs($r['data'], 'inventorySetQuantities');
    if ($e) out(['ok' => false, 'error' => implode('; ', $e)], 400);

    $ch = $r['data']['inventorySetQuantities']['inventoryAdjustmentGroup']['changes'][0] ?? null;
    out(['ok' => true, 'quantity' => $ch['quantityAfterChange'] ?? $qty, 'delta' => $ch['delta'] ?? null]);
}

/* ═══════════════════════════════════════════════════════════════════════════
   CUSTOMERS
   ═══════════════════════════════════════════════════════════════════════════ */

case 'customer': {
    $id = trim((string)($in['id'] ?? ''));
    if ($id === '') out(['ok' => false, 'error' => 'id required'], 400);

    $before = shop_query('query($id: ID!) { customer(id: $id) {
        firstName lastName email phone note tags } }', ['id' => gid('Customer', $id)]);
    if (!$before['ok']) out(['ok' => false, 'error' => $before['message']], 400);

    $input = ['id' => gid('Customer', $id)];
    foreach ([['first_name', 'firstName'], ['last_name', 'lastName'],
              ['email', 'email'], ['phone', 'phone'], ['note', 'note']] as [$k, $f]) {
        $v = given($in, $k);
        if ($v === '__skip__') continue;
        $input[$f] = $v;
    }
    if (array_key_exists('tags', $in)) {
        $t = $in['tags'];
        $input['tags'] = is_array($t)
            ? array_values(array_filter(array_map('trim', $t)))
            : array_values(array_filter(array_map('trim', explode(',', (string)$t))));
    }
    if (count($input) === 1) out(['ok' => false, 'error' => 'Nothing to change'], 400);

    $r = shop_query('
        mutation($input: CustomerInput!) {
          customerUpdate(input: $input) {
            customer { id firstName lastName email phone note tags }
            userErrors { field message }
          }
        }', ['input' => $input]);

    if (!$r['ok']) out(['ok' => false, 'error' => $r['message']], 400);
    $e = errs($r['data'], 'customerUpdate');
    if ($e) out(['ok' => false, 'error' => implode('; ', $e)], 400);

    out(['ok' => true, 'before' => $before['data']['customer'] ?? null,
         'after' => $r['data']['customerUpdate']['customer'] ?? null]);
}

case 'customer_create': {
    $email = trim((string)($in['email'] ?? ''));
    $phone = trim((string)($in['phone'] ?? ''));
    if ($email === '' && $phone === '') {
        out(['ok' => false, 'error' => 'A customer needs an email or a phone number'], 400);
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        out(['ok' => false, 'error' => 'That does not look like an email address'], 400);
    }

    $input = [];
    foreach ([['first_name', 'firstName'], ['last_name', 'lastName'],
              ['email', 'email'], ['phone', 'phone'], ['note', 'note']] as [$k, $f]) {
        $v = trim((string)($in[$k] ?? ''));
        if ($v !== '') $input[$f] = $v;
    }
    if (!empty($in['tags'])) {
        $t = $in['tags'];
        $input['tags'] = is_array($t) ? $t : array_map('trim', explode(',', (string)$t));
    }

    /* an address, if one was given */
    $addr = [];
    foreach ([['address1', 'address1'], ['address2', 'address2'], ['city', 'city'],
              ['province', 'province'], ['zip', 'zip'], ['country', 'country']] as [$k, $f]) {
        $v = trim((string)($in[$k] ?? ''));
        if ($v !== '') $addr[$f] = $v;
    }
    if ($addr) {
        if (!empty($input['firstName'])) $addr['firstName'] = $input['firstName'];
        if (!empty($input['lastName']))  $addr['lastName']  = $input['lastName'];
        $input['addresses'] = [$addr];
    }

    $r = shop_query('
        mutation($input: CustomerInput!) {
          customerCreate(input: $input) {
            customer { id displayName email phone }
            userErrors { field message }
          }
        }', ['input' => $input]);

    if (!$r['ok']) out(['ok' => false, 'error' => $r['message']], 400);
    $e = errs($r['data'], 'customerCreate');
    if ($e) out(['ok' => false, 'error' => implode('; ', $e)], 400);

    $c = $r['data']['customerCreate']['customer'] ?? null;
    out(['ok' => true, 'customer' => $c, 'id' => gid_num($c['id'] ?? '')]);
}

/* ═══════════════════════════════════════════════════════════════════════════
   ORDERS
   ═══════════════════════════════════════════════════════════════════════════ */

case 'order': {
    $id = trim((string)($in['id'] ?? ''));
    if ($id === '') out(['ok' => false, 'error' => 'id required'], 400);

    $input = ['id' => gid('Order', $id)];
    foreach ([['note', 'note'], ['email', 'email']] as [$k, $f]) {
        $v = given($in, $k);
        if ($v === '__skip__') continue;
        $input[$f] = $v;
    }
    if (array_key_exists('tags', $in)) {
        $t = $in['tags'];
        $input['tags'] = is_array($t)
            ? array_values(array_filter(array_map('trim', $t)))
            : array_values(array_filter(array_map('trim', explode(',', (string)$t))));
    }

    /* shipping address, if any part of it was sent */
    $addr = [];
    foreach (['address1', 'address2', 'city', 'province', 'zip', 'country', 'phone'] as $f) {
        if (array_key_exists('ship_' . $f, $in)) $addr[$f] = trim((string)$in['ship_' . $f]);
    }
    if (array_key_exists('ship_name', $in)) {
        $parts = preg_split('/\s+/', trim((string)$in['ship_name']), 2);
        $addr['firstName'] = $parts[0] ?? '';
        $addr['lastName']  = $parts[1] ?? '';
    }
    if ($addr) $input['shippingAddress'] = $addr;

    if (count($input) === 1) out(['ok' => false, 'error' => 'Nothing to change'], 400);

    $r = shop_query('
        mutation($input: OrderInput!) {
          orderUpdate(input: $input) {
            order { id name note tags email
                    shippingAddress { name address1 city province zip country } }
            userErrors { field message }
          }
        }', ['input' => $input]);

    if (!$r['ok']) out(['ok' => false, 'error' => $r['message']], 400);
    $e = errs($r['data'], 'orderUpdate');
    if ($e) out(['ok' => false, 'error' => implode('; ', $e)], 400);

    out(['ok' => true, 'order' => $r['data']['orderUpdate']['order'] ?? null]);
}

/* ═══ cancel ═══════════════════════════════════════════════════════════════
   Cancelling cannot be undone. It also defaults to NOT refunding — moving
   money is a separate decision and should be made on purpose. */
case 'order_cancel': {
    $id = trim((string)($in['id'] ?? ''));
    if ($id === '') out(['ok' => false, 'error' => 'id required'], 400);
    require_typed($in, 'CANCEL');

    $reason = strtoupper(trim((string)($in['reason'] ?? 'OTHER')));
    if (!in_array($reason, ['CUSTOMER', 'DECLINED', 'FRAUD', 'INVENTORY', 'STAFF', 'OTHER'], true)) {
        $reason = 'OTHER';
    }

    $r = shop_query('
        mutation($orderId: ID!, $reason: OrderCancelReason!, $refund: Boolean!,
                 $restock: Boolean!, $notify: Boolean) {
          orderCancel(orderId: $orderId, reason: $reason, refund: $refund,
                      restock: $restock, notifyCustomer: $notify) {
            job { id }
            orderCancelUserErrors { field message }
          }
        }', [
            'orderId' => gid('Order', $id),
            'reason'  => $reason,
            'refund'  => !empty($in['refund']),
            'restock' => !isset($in['restock']) || !empty($in['restock']),
            'notify'  => !empty($in['notify']),
        ]);

    if (!$r['ok']) out(['ok' => false, 'error' => $r['message']], 400);
    $e = [];
    foreach ($r['data']['orderCancel']['orderCancelUserErrors'] ?? [] as $x) {
        $e[] = ($x['field'] ? implode('.', (array)$x['field']) . ': ' : '') . ($x['message'] ?? '');
    }
    if ($e) out(['ok' => false, 'error' => implode('; ', $e)], 400);

    /* cancellation runs as a background job, so it may not be done yet */
    out(['ok' => true, 'queued' => true,
         'refunded' => !empty($in['refund']),
         'note' => 'Shopify processes cancellations in the background — it may take a moment to show.']);
}

/* ═══════════════════════════════════════════════════════════════════════════
   PAYMENTS
   ═══════════════════════════════════════════════════════════════════════════ */

case 'order_capture': {
    $id = trim((string)($in['id'] ?? ''));
    if ($id === '') out(['ok' => false, 'error' => 'id required'], 400);

    /* capture needs the authorisation transaction to charge against */
    $look = shop_query('
        query($id: ID!) {
          order(id: $id) {
            name
            totalPriceSet { shopMoney { amount currencyCode } }
            transactions(first: 10) { id kind status }
          }
        }', ['id' => gid('Order', $id)]);
    if (!$look['ok']) out(['ok' => false, 'error' => $look['message']], 400);

    $auth = null;
    foreach ($look['data']['order']['transactions'] ?? [] as $t) {
        if (($t['kind'] ?? '') === 'AUTHORIZATION' && ($t['status'] ?? '') === 'SUCCESS') {
            $auth = $t['id'];
        }
    }
    if (!$auth) {
        out(['ok' => false, 'error' => 'Nothing to capture — there is no successful authorisation '
            . 'on this order. It may already be captured, or paid another way.'], 400);
    }

    $amount = trim((string)($in['amount'] ?? ''));
    if ($amount === '') $amount = $look['data']['order']['totalPriceSet']['shopMoney']['amount'] ?? '';
    $cur = $look['data']['order']['totalPriceSet']['shopMoney']['currencyCode'] ?? 'USD';

    $r = shop_query('
        mutation($input: OrderCaptureInput!) {
          orderCapture(input: $input) {
            transaction { id status amountSet { shopMoney { amount currencyCode } } }
            userErrors { field message }
          }
        }', ['input' => [
            'id' => gid('Order', $id),
            'parentTransactionId' => $auth,
            'amount' => (string)$amount,
            'currency' => $cur,
        ]]);

    if (!$r['ok']) out(['ok' => false, 'error' => $r['message']], 400);
    $e = errs($r['data'], 'orderCapture');
    if ($e) out(['ok' => false, 'error' => implode('; ', $e)], 400);

    out(['ok' => true, 'transaction' => $r['data']['orderCapture']['transaction'] ?? null]);
}

/* Marks an order paid in Shopify's books. It does not take any money — use it
   when payment arrived some other way. */
case 'order_paid': {
    $id = trim((string)($in['id'] ?? ''));
    if ($id === '') out(['ok' => false, 'error' => 'id required'], 400);

    $r = shop_query('
        mutation($input: OrderMarkAsPaidInput!) {
          orderMarkAsPaid(input: $input) {
            order { id name displayFinancialStatus }
            userErrors { field message }
          }
        }', ['input' => ['id' => gid('Order', $id)]]);

    if (!$r['ok']) out(['ok' => false, 'error' => $r['message']], 400);
    $e = errs($r['data'], 'orderMarkAsPaid');
    if ($e) out(['ok' => false, 'error' => implode('; ', $e)], 400);

    out(['ok' => true, 'order' => $r['data']['orderMarkAsPaid']['order'] ?? null,
         'note' => 'Marked paid in Shopify. No money was taken — this only records that it arrived.']);
}

/* ═══ refund ═══════════════════════════════════════════════════════════════
   This sends money back and cannot be reversed. */
case 'refund': {
    $id = trim((string)($in['id'] ?? ''));
    if ($id === '') out(['ok' => false, 'error' => 'id required'], 400);
    require_typed($in, 'REFUND');

    $look = shop_query('
        query($id: ID!) {
          order(id: $id) {
            name
            totalPriceSet { shopMoney { amount currencyCode } }
            totalRefundedSet { shopMoney { amount } }
            transactions(first: 20) { id kind status
              amountSet { shopMoney { amount currencyCode } } }
          }
        }', ['id' => gid('Order', $id)]);
    if (!$look['ok']) out(['ok' => false, 'error' => $look['message']], 400);

    $o = $look['data']['order'] ?? null;
    if (!$o) out(['ok' => false, 'error' => 'No such order'], 404);

    /* refund against the transaction that took the money */
    $parent = null;
    $parentAmt = 0.0;
    foreach ($o['transactions'] ?? [] as $t) {
        if (in_array($t['kind'] ?? '', ['SALE', 'CAPTURE'], true) && ($t['status'] ?? '') === 'SUCCESS') {
            $parent = $t['id'];
            $parentAmt = (float)($t['amountSet']['shopMoney']['amount'] ?? 0);
        }
    }
    if (!$parent) {
        out(['ok' => false, 'error' => 'Nothing to refund against — no successful payment is '
            . 'recorded on this order.'], 400);
    }

    $total    = (float)($o['totalPriceSet']['shopMoney']['amount'] ?? 0);
    $already  = (float)($o['totalRefundedSet']['shopMoney']['amount'] ?? 0);
    $cur      = $o['totalPriceSet']['shopMoney']['currencyCode'] ?? 'USD';
    $maxLeft  = round($total - $already, 2);

    $amount = trim((string)($in['amount'] ?? ''));
    $amount = ($amount === '') ? $maxLeft : (float)$amount;

    if ($amount <= 0)        out(['ok' => false, 'error' => 'The amount must be more than zero'], 400);
    if ($amount > $maxLeft + 0.001) {
        out(['ok' => false, 'error' => 'That is more than is left to refund on ' . $o['name']
            . '. At most ' . number_format($maxLeft, 2) . ' ' . $cur
            . ($already > 0 ? ', since ' . number_format($already, 2) . ' has already gone back.' : '.')], 400);
    }

    $r = shop_query('
        mutation($input: RefundInput!) {
          refundCreate(input: $input) {
            refund { id totalRefundedSet { shopMoney { amount currencyCode } } }
            userErrors { field message }
          }
        }', ['input' => [
            'orderId' => gid('Order', $id),
            'note'    => trim((string)($in['note'] ?? '')) ?: null,
            'notify'  => !empty($in['notify']),
            'transactions' => [[
                'orderId' => gid('Order', $id),
                'gateway' => null,
                'kind'    => 'REFUND',
                'amount'  => number_format($amount, 2, '.', ''),
                'parentId' => $parent,
            ]],
        ]]);

    if (!$r['ok']) out(['ok' => false, 'error' => $r['message']], 400);
    $e = errs($r['data'], 'refundCreate');
    if ($e) out(['ok' => false, 'error' => implode('; ', $e)], 400);

    out(['ok' => true,
         'refunded' => $r['data']['refundCreate']['refund']['totalRefundedSet']['shopMoney'] ?? null,
         'order' => $o['name']]);
}

/* Creates the two definitions on products and on variants. Safe to run more
   than once — a definition that already exists is reported and skipped rather
   than treated as a failure. */
case 'metafield_setup': {
    $made = [];
    $already = [];
    $failed = [];

    foreach (['PRODUCT', 'PRODUCTVARIANT'] as $owner) {
        foreach (MF_FIELDS as $key => $f) {
            $r = shop_query('
                mutation($definition: MetafieldDefinitionInput!) {
                  metafieldDefinitionCreate(definition: $definition) {
                    createdDefinition { id name key namespace ownerType }
                    userErrors { field message code }
                  }
                }', ['definition' => [
                    'name'        => $f['name'],
                    'namespace'   => MF_NAMESPACE,
                    'key'         => $key,
                    'description' => $f['desc'],
                    'type'        => $f['type'],
                    'ownerType'   => $owner,
                    /* No access block. Setting one is only permitted for apps
                       with a level of trust this one does not have, and
                       Shopify's default already makes the field editable in
                       admin — which is the only reason it was here. */
                ]]);

            if (!$r['ok']) { $failed[] = "$owner.$key: " . $r['message']; continue; }

            $ue = $r['data']['metafieldDefinitionCreate']['userErrors'] ?? [];
            if ($ue) {
                $taken = false;
                foreach ($ue as $x) {
                    if (($x['code'] ?? '') === 'TAKEN'
                        || stripos($x['message'] ?? '', 'already') !== false) $taken = true;
                }
                if ($taken) $already[] = "$owner.$key";
                else $failed[] = "$owner.$key: " . ($ue[0]['message'] ?? 'unknown');
                continue;
            }
            $made[] = "$owner.$key";
        }
    }

    if (!$made && !$already) {
        out(['ok' => false, 'error' => implode(' · ', $failed) ?: 'Nothing was created'], 400);
    }
    out(['ok' => true, 'created' => $made, 'existed' => $already,
         'failed' => $failed,
         'note' => 'These now appear on products and variants in Shopify admin as well.']);
}

/* Sets one or both values on a product or a variant. */
case 'metafield': {
    $id   = trim((string)($in['id'] ?? ''));
    $kind = strtolower(trim((string)($in['kind'] ?? 'product')));
    if ($id === '') out(['ok' => false, 'error' => 'id required'], 400);
    if (!in_array($kind, ['product', 'variant'], true)) {
        out(['ok' => false, 'error' => 'kind must be product or variant'], 400);
    }

    $owner = $kind === 'variant' ? gid('ProductVariant', $id) : gid('Product', $id);

    $set = [];
    $del = [];
    foreach (MF_FIELDS as $key => $f) {
        if (!array_key_exists($key, $in)) continue;
        $v = trim((string)$in[$key]);

        if ($f['type'] === 'number_decimal' && $v !== '' && !is_numeric($v)) {
            out(['ok' => false, 'error' => $f['name'] . ' must be a number'], 400);
        }
        /* Blank is allowed, and the two types need different handling for it.
           Text can hold an empty string, so the field stays on the product
           with nothing in it. A decimal cannot — Shopify rejects '' as a
           number — so blank there means removing the value, which is the only
           way to say "not known yet" for a number. Either way the definition
           remains, so the field still shows on every product. */
        if ($v === '') {
            if ($f['type'] === 'number_decimal') {
                $del[] = ['ownerId' => $owner, 'namespace' => MF_NAMESPACE, 'key' => $key];
            } else {
                $set[] = [
                    'ownerId'   => $owner,
                    'namespace' => MF_NAMESPACE,
                    'key'       => $key,
                    'type'      => $f['type'],
                    'value'     => '',
                ];
            }
            continue;
        }
        $set[] = [
            'ownerId'   => $owner,
            'namespace' => MF_NAMESPACE,
            'key'       => $key,
            'type'      => $f['type'],
            'value'     => $v,
        ];
    }

    $out = [];
    $problems = [];

    if ($set) {
        $r = shop_query('
            mutation($metafields: [MetafieldsSetInput!]!) {
              metafieldsSet(metafields: $metafields) {
                metafields { id namespace key value type }
                userErrors { field message code }
              }
            }', ['metafields' => $set]);

        if (!$r['ok']) $problems[] = $r['message'];
        else {
            $e = errs($r['data'], 'metafieldsSet');
            if ($e) $problems[] = implode('; ', $e);
            else foreach ($r['data']['metafieldsSet']['metafields'] ?? [] as $mf) {
                $out[$mf['key']] = $mf['value'];
            }
        }
    }

    if (!empty($del)) {
        $r2 = shop_query('
            mutation($metafields: [MetafieldIdentifierInput!]!) {
              metafieldsDelete(metafields: $metafields) {
                deletedMetafields { key namespace }
                userErrors { field message }
              }
            }', ['metafields' => $del]);
        if ($r2['ok']) {
            foreach ($r2['data']['metafieldsDelete']['deletedMetafields'] ?? [] as $d) {
                $out[$d['key']] = null;
            }
        }
        /* a delete that fails because there was nothing there is not a problem */
    }

    if (!$out && $problems) out(['ok' => false, 'error' => implode(' · ', $problems)], 400);

    /* Clearing both fields leaves nothing in $out but is still a real save —
       treating it as "nothing to change" would silently refuse to empty a
       field somebody deliberately emptied. */
    if (!$out && !$problems && !$set && !$del) {
        out(['ok' => false, 'error' => 'No metafields were named'], 400);
    }

    out(['ok' => true, 'values' => $out,
         'partial' => $problems ? implode(' · ', $problems) : null]);
}

default:
    out(['ok' => false, 'error' => 'Unknown action: ' . $action], 400);
}
