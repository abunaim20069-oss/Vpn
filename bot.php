<?php

require_once __DIR__ . '/vendor/autoload.php';

use Telegram\Bot\Api;
use Telegram\Bot\Objects\Update;
use Telegram\Bot\Keyboard\Keyboard;
use Telegram\Bot\Keyboard\ForceReply;

// ========== CONFIG ==========
const BOT_TOKEN = "8225353587:AAFDEsuXloUGiifIAv4F10_5Gw8adq-uobA"; // (This token should be kept private in a real bot)
const ADMIN_ID  = 5830499612;  // !!! CHANGE THIS TO YOUR ADMIN TELEGRAM USER ID !!!
const DATA_FILE = "bot_data.json";
const STATE_FILE = "user_states.json"; // To manage multi-step conversations

$telegram = new Api(BOT_TOKEN);

// ========== DATA HANDLING ==========
function load_data() {
    if (file_exists(DATA_FILE)) {
        $data = json_decode(file_get_contents(DATA_FILE), true);
    } else {
        $data = [];
    }
    $data = array_merge([
        "products" => [], // { "VPN_Name": [{"gmail": "...", "password": "..."}] }
        "balances" => [],
        "pending_payments" => [],
        "unmatched_payments" => [],
        "orders" => [],
        "total_sales" => 0.0,
    ], $data);
    return $data;
}

function save_data($d) {
    file_put_contents(DATA_FILE, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function load_states() {
    if (file_exists(STATE_FILE)) {
        return json_decode(file_get_contents(STATE_FILE), true);
    }
    return [];
}

function save_states($states) {
    file_put_contents(STATE_FILE, json_encode($states, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}


$data               = load_data();
$products           = &$data["products"]; // Use reference to modify directly
$balances           = &$data["balances"];
$pending_payments   = &$data["pending_payments"];
$unmatched_payments = &$data["unmatched_payments"];
$orders             = &$data["orders"];
$total_sales        = &$data["total_sales"];
$user_states        = load_states();


// Updated vpn_prices structure based on your provided list
$vpn_prices = [
    "Express VPN" => ["price" => 30, "days" => 7],
    "Nord VPN" => ["price" => 40, "days" => 7],
    "PIA VPN" => ["price" => 30, "days" => 7],
    "Surfshark" => ["price" => 30, "days" => 7],
    "HotspotShield VPN" => ["price" => 30, "days" => 7],
    "HMA VPN" => ["price" => 30, "days" => 7],
    "IPVanish VPN" => ["price" => 30, "days" => 7],
    "Cyberghost VPN" => ["price" => 15, "days" => 3], // Changed to 3 Days
    "Vypr VPN" => ["price" => 15, "days" => 3],    // Changed to 3 Days
    "X VPN" => ["price" => 30, "days" => 7],
    "Pure VPN" => ["price" => 30, "days" => 7],
    "Panda VPN" => ["price" => 15, "days" => 3],   // Changed to 3 Days
    "Turbo VPN" => ["price" => 30, "days" => 7],
    "Sky VPN" => ["price" => 30, "days" => 7],
    "Potato VPN" => ["price" => 30, "days" => 7],
    "Zoog VPN" => ["price" => 15, "days" => 3],    // Changed to 3 Days
    "Bitdefender VPN" => ["price" => 30, "days" => 7]
];

// --- NEW: Define expected fields for each VPN type ---
$product_fields = [
    "ExpressVPN" => ["Gmail", "Password", "PC Key"],
    "HMA VPN" => ["Activation Key"], // HMA will only have an activation key (adjusted key name)
    // Default for others (if not specified here, it falls back to a generic GMail/Password)
    // You can explicitly list other VPNs if they have unique fields.
    // For now, if a VPN is not in this dict, it will use the default "Gmail", "Password".
];
// --- END NEW ---

// Payment gateway number (updated to your specified number)
const PAYMENT_NUMBER = "+8801813975847";

// Helper functions
function main_menu_markup() {
    return Keyboard::make([
        'keyboard' => [
            ['🛒 Buy Products', '💰 Add Balance'],
            ['📦 My Orders', '💳 Balance']
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => false
    ]);
}

function admin_menu_markup() {
    return Keyboard::make([
        'keyboard' => [
            ['📊 Total Sales', '📈 Current Stock'],
            ['➕ Add VPN Account', '⬅️ Main Menu (User)']
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => false
    ]);
}

function norm_text($s) {
    if (!is_string($s)) return "";
    return strtolower(implode(" ", array_filter(explode(" ", trim($s)))));
}

function ensure_user(&$balances, &$orders, $uid) {
    $balances[$uid] = $balances[$uid] ?? 0.0;
    $orders[$uid] = $orders[$uid] ?? [];
}

function parse_trx_id($text) {
    if (preg_match('/TrxID[:\s]+([A-Za-z0-9]+)/i', $text, $matches_bkash)) {
        return strtolower($matches_bkash[1]);
    }
    if (preg_match('/TxnID[:\s]+([A-Za-z0-9]+)/i', $text, $matches_nagad)) {
        return strtolower($matches_nagad[1]);
    }
    return null;
}

function parse_amount($text) {
    // Remove commas before parsing
    $text = str_replace(",", "", $text);
    if (preg_match('/\bTk\s?([0-9]+(?:\.[0-9]{1,2})?)\b/i', $text, $matches)) {
        return (float)$matches[1];
    }
    return null;
}

// Function to send message
function sendMessage($chatId, $text, $replyMarkup = null, $parseMode = null) {
    global $telegram;
    $params = ['chat_id' => $chatId, 'text' => $text];
    if ($replyMarkup) $params['reply_markup'] = $replyMarkup;
    if ($parseMode) $params['parse_mode'] = $parseMode;
    return $telegram->sendMessage($params);
}

// Function to edit message
function editMessageText($chatId, $messageId, $text, $replyMarkup = null, $parseMode = null) {
    global $telegram;
    $params = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text];
    if ($replyMarkup) $params['reply_markup'] = $replyMarkup;
    if ($parseMode) $params['parse_mode'] = $parseMode;
    try {
        $telegram->editMessageText($params);
    } catch (Exception $e) {
        // Log the error, or simply ignore if it's due to message not changing
        error_log("Failed to edit message: " . $e->getMessage());
    }
}

// Function to answer callback query
function answerCallbackQuery($callbackId, $text = null, $showAlert = false) {
    global $telegram;
    $params = ['callback_query_id' => $callbackId];
    if ($text) $params['text'] = $text;
    $params['show_alert'] = $showAlert;
    $telegram->answerCallbackQuery($params);
}

// Main handler for updates
function handleUpdate(Update $update) {
    global $telegram, $data, $products, $balances, $pending_payments, $unmatched_payments, $orders, $total_sales, $vpn_prices, $product_fields, $user_states;

    $chatId = $update->getChat()->getId();
    $uid = (string) $update->getFrom()->getId();
    ensure_user($balances, $orders, $uid); // Ensure user data exists

    $message = $update->getMessage();
    $callbackQuery = $update->getCallbackQuery();

    if ($message) {
        $text = $message->getText();

        // Check for active user state for multi-step conversations
        if (isset($user_states[$uid])) {
            $state = $user_states[$uid];
            unset($user_states[$uid]); // Clear state after use
            save_states($user_states);

            if ($state['handler'] === 'save_trx_id') {
                save_trx_id($message);
                return;
            } elseif ($state['handler'] === 'process_add_vpn_account') {
                process_add_vpn_account($message, $state['vpn_name']);
                return;
            }
        }

        switch (norm_text($text)) {
            case '/start':
            case '/admin':
                start_or_admin($message);
                break;
            case '💳 balance':
                show_balance($message);
                break;
            case '🛒 buy products':
                show_vpn_list($message);
                break;
            case '📦 my orders':
                show_my_orders($message);
                break;
            case '💰 add balance':
                add_balance_ui($message);
                break;
            case '⬅️ main menu (user)':
                if ($uid == ADMIN_ID) {
                    back_to_main_menu_admin($message);
                } else {
                    echo_all($message); // User shouldn't hit this, but just in case
                }
                break;
            case '📊 total sales':
                if ($uid == ADMIN_ID) {
                    show_total_sales($message);
                } else {
                    echo_all($message);
                }
                break;
            case '📈 current stock':
                if ($uid == ADMIN_ID) {
                    show_current_stock($message);
                } else {
                    echo_all($message);
                }
                break;
            case '➕ add vpn account':
                if ($uid == ADMIN_ID) {
                    ask_add_vpn_account($message);
                } else {
                    echo_all($message);
                }
                break;
            default:
                // Admin bKash/Nagad parser for forwarded messages
                if ($uid == ADMIN_ID && preg_match('/(trxid|txnid|trnx id)/i', $text) && preg_match('/tk/i', $text) && preg_match('/(received|prepaid|cash in)/i', $text)) {
                    admin_bkash_nagad_parser($message);
                } else {
                    echo_all($message);
                }
                break;
        }
    } elseif ($callbackQuery) {
        handleCallbackQuery($callbackQuery);
    }

    save_data($data); // Save data after every update
}

// ========== START COMMANDS ==========
function start_or_admin($message) {
    global $telegram, $data, $balances, $orders;
    $uid = (string) $message->getFrom()->getId();
    ensure_user($balances, $orders, $uid);
    if ($uid == ADMIN_ID) {
        sendMessage($message->getChat()->getId(), "👋 Welcome Admin! Choose an option:", admin_menu_markup());
    } else {
        sendMessage($message->getChat()->getId(), "👋 Welcome! Choose an option:", main_menu_markup());
    }
}

function show_balance($message) {
    global $balances;
    $uid = (string) $message->getFrom()->getId();
    sendMessage($message->getChat()->getId(), sprintf("💳 Your current balance: %.2f৳", $balances[$uid] ?? 0.0), main_menu_markup());
}

// ========== BUY PRODUCTS ==========
function show_vpn_list($message) {
    global $vpn_prices, $products;
    $keyboard = Keyboard::make()->inline();
    foreach ($vpn_prices as $name => $data_item) {
        $price = $data_item["price"];
        $days = $data_item["days"];
        $stock_count = count($products[$name] ?? []);

        $status_icon = ($stock_count > 0) ? "✅" : "🔴";
        $keyboard->row(Keyboard::inlineButton(['text' => "{$name} {$days} Days {$price}৳ {$status_icon}", 'callback_data' => "vpn|{$name}"]));
    }
    sendMessage($message->getChat()->getId(), "📋 Available VPNs:", $keyboard);
}

function handleCallbackQuery($callbackQuery) {
    global $telegram, $data, $products, $balances, $pending_payments, $unmatched_payments, $orders, $total_sales, $vpn_prices, $product_fields, $user_states;

    $chatId = $callbackQuery->getMessage()->getChat()->getId();
    $messageId = $callbackQuery->getMessage()->getMessageId();
    $uid = (string) $callbackQuery->getFrom()->getId();
    $callbackData = $callbackQuery->getData();

    if (str_starts_with($callbackData, "vpn|")) {
        $vpn_name = explode("|", $callbackData)[1];
        $vpn_info = $vpn_prices[$vpn_name] ?? null;

        if (!$vpn_info) {
            editMessageText($chatId, $messageId, "❌ VPN not found.");
            answerCallbackQuery($callbackQuery->getId(), "VPN not found.", true);
            return;
        }

        $price = $vpn_info["price"];
        $days = $vpn_info["days"];
        $bal = $balances[$uid] ?? 0.0;
        $stock_count = count($products[$vpn_name] ?? []);

        $keyboard = Keyboard::make()->inline();
        $message_text = (
            "🛍 *{$vpn_name}* ({$days} Days)\n" .
            "Price: {$price}৳\n" .
            sprintf("Your Balance: %.2f৳\n\n", $bal)
        );

        if ($stock_count == 0) {
            answerCallbackQuery($callbackQuery->getId(), "This VPN is currently out of stock. Please choose another.", true);
            $message_text .= "🚫 This VPN is currently *Out of Stock*.";
        } elseif ($bal < $price) {
            answerCallbackQuery($callbackQuery->getId(), "Insufficient balance. Please add funds.", true);
            $message_text .= "💰 Insufficient balance. Please add funds.";
            $keyboard->row(Keyboard::inlineButton(['text' => "➕ Add Balance", 'callback_data' => "add_balance_shortcut"]));
        } else {
            $message_text .= "Ready to purchase!";
            $keyboard->row(Keyboard::inlineButton(['text' => "✅ Buy Now", 'callback_data' => "buy|{$vpn_name}"]));
        }

        $keyboard->row(Keyboard::inlineButton(['text' => "❌ Cancel", 'callback_data' => "cancel_vpn_selection"]));
        $keyboard->row(Keyboard::inlineButton(['text' => "🏠 Main Menu", 'callback_data' => "back_to_main_menu"]));

        editMessageText($chatId, $messageId, $message_text, $keyboard, "Markdown");
    } elseif ($callbackData == "cancel_vpn_selection") {
        editMessageText($chatId, $messageId, "Selection cancelled. Returning to main menu.");
        sendMessage($chatId, "Choose an option:", main_menu_markup());
        answerCallbackQuery($callbackQuery->getId(), "Cancelled.");
    } elseif ($callbackData == "back_to_main_menu") {
        editMessageText($chatId, $messageId, "Returning to main menu.");
        sendMessage($chatId, "Choose an option:", main_menu_markup());
        answerCallbackQuery($callbackQuery->getId(), "Back to main menu.");
    } elseif (str_starts_with($callbackData, "buy|")) {
        $vpn_name = explode("|", $callbackData)[1];
        $vpn_info = $vpn_prices[$vpn_name] ?? null;

        if (!$vpn_info) {
            editMessageText($chatId, $messageId, "❌ VPN not found.");
            sendMessage($chatId, "⬅️ Back to menu:", main_menu_markup());
            answerCallbackQuery($callbackQuery->getId(), "VPN not found.", true);
            return;
        }

        $price = $vpn_info["price"];
        $user_balance = $balances[$uid] ?? 0.0;
        $vpn_stock = $products[$vpn_name] ?? [];

        if ($user_balance >= $price && count($vpn_stock) > 0) {
            $item = array_shift($products[$vpn_name]); // Take one item from stock
            $balances[$uid] = round($user_balance - $price, 2); // Update balance
            $orders[$uid][] = ["vpn_name" => $vpn_name, "item" => $item, "timestamp" => date("Y-m-d H:i:s")];

            $total_sales += $price;
            save_data($data);

            $msg_details = "🎁 Your *{$vpn_name}* VPN details ({$vpn_info['days']} Days ⭐):\n\n";

            $fields_to_display = $product_fields[$vpn_name] ?? ["Gmail", "Password"];

            foreach ($fields_to_display as $field_name) {
                $item_key = str_replace(" ", "_", strtolower($field_name));
                $msg_details .= "*{$field_name}* ➡ `{$item[$item_key] ?? 'N/A'}`\n";
            }

            editMessageText($chatId, $messageId, $msg_details, null, "Markdown");
            sendMessage($chatId, "✅ Purchase successful! You can find this in '📦 My Orders'.", main_menu_markup());
            answerCallbackQuery($callbackQuery->getId(), "Purchase successful!", true);
        } else {
            $error_msg = "";
            if (count($vpn_stock) == 0) {
                $error_msg = "🚫 This VPN is currently *Out of Stock*.";
            } elseif ($user_balance < $price) {
                $error_msg = "💰 Insufficient balance. Please add funds.";
            } else {
                $error_msg = "❌ VPN unavailable or insufficient balance. Please try again.";
            }

            editMessageText($chatId, $messageId, "{$error_msg}\n\n🏠 Returning to main menu.", null, "Markdown");
            sendMessage($chatId, "Choose an option:", main_menu_markup());
            answerCallbackQuery($callbackQuery->getId(), $error_msg, true);
        }
    } elseif ($callbackData == "add_balance_shortcut") {
        add_balance_shortcut($callbackQuery);
    } elseif (str_starts_with($callbackData, "add_balance_")) {
        $method = ucfirst(explode("_", $callbackData)[2]);
        $keyboard = Keyboard::make()->inline();
        $keyboard->row(Keyboard::inlineButton(['text' => "✅ Payment Done", 'callback_data' => "send_trx"]));

        editMessageText(
            $chatId, $messageId,
            "Send money to our {$method} number:\n`" . PAYMENT_NUMBER . "`\n\n" .
            "After sending money, tap '✅ Payment Done' and enter your Transaction ID.",
            $keyboard, "Markdown"
        );
        answerCallbackQuery($callbackQuery->getId(), "Showing {$method} payment details.");
    } elseif ($callbackData == "send_trx") {
        $user_states[$uid] = ['handler' => 'save_trx_id'];
        save_states($user_states);

        $forceReply = ForceReply::make(['selective' => true]);
        sendMessage($chatId, "📥 Enter your TRX ID:", $forceReply);
        answerCallbackQuery($callbackQuery->getId(), "Please send your TRX ID.");
    } elseif (str_starts_with($callbackData, "admin_add_vpn|")) {
        $vpn_name = explode("|", $callbackData)[1];
        
        $user_states[$uid] = ['handler' => 'process_add_vpn_account', 'vpn_name' => $vpn_name];
        save_states($user_states);

        $prompt_fields = $product_fields[$vpn_name] ?? ["Gmail", "Password"];
        
        $prompt_text = "You selected *{$vpn_name}*.\n\nPlease send the VPN account details in the following format:\n\n";
        $format_example = "";
        foreach ($prompt_fields as $field) {
            $format_example .= "*{$field}*:your_". strtolower(str_replace(" ", "_", $field)) . "_value\n";
        }
        
        $prompt_text .= "`" . trim($format_example) . "`";

        $forceReply = ForceReply::make(['selective' => true]);
        sendMessage($chatId, $prompt_text, $forceReply, "Markdown");
        answerCallbackQuery($callbackQuery->getId(), "Ready to add {$vpn_name} account.");
    }
}

// ========== MY ORDERS ==========
function show_my_orders($message) {
    global $orders, $product_fields;
    $uid = (string) $message->getFrom()->getId();
    $user_orders = $orders[$uid] ?? [];

    if (empty($user_orders)) {
        sendMessage($message->getChat()->getId(), "You haven't purchased any VPNs yet! Go to '🛒 Buy Products' to get started.", main_menu_markup());
        return;
    }

    $order_list_text = "🛍 Your Recent Orders:\n\n";
    // Show last 5 orders
    $recent_orders = array_slice($user_orders, -5);

    foreach ($recent_orders as $i => $order_item) {
        $vpn_name = $order_item["vpn_name"] ?? "N/A";
        $item_details = $order_item["item"] ?? [];
        $timestamp = $order_item["timestamp"] ?? "N/A";

        $order_list_text .= "*" . ($i + 1) . ". {$vpn_name}* (Purchased: {$timestamp})\n";

        $fields_to_display = $product_fields[$vpn_name] ?? ["Gmail", "Password"];
        foreach ($fields_to_display as $field_name) {
            $item_key = str_replace(" ", "_", strtolower($field_name));
            $order_list_text .= "  *{$field_name}:* `{$item_details[$item_key] ?? 'N/A'}`\n";
        }
        $order_list_text .= "\n";
    }

    sendMessage($message->getChat()->getId(), $order_list_text, main_menu_markup(), "Markdown");
}


// ========== ADD BALANCE ==========
function add_balance_ui($message) {
    $keyboard = Keyboard::make()->inline();
    $keyboard->row(Keyboard::inlineButton(['text' => "bKash 🔴", 'callback_data' => "add_balance_bkash"]));
    $keyboard->row(Keyboard::inlineButton(['text' => "Nagad 🟠", 'callback_data' => "add_balance_nagad"]));
    sendMessage($message->getChat()->getId(), "Choose your payment method:", $keyboard);
}

function add_balance_shortcut($callbackQuery) {
    $chatId = $callbackQuery->getMessage()->getChat()->getId();
    $messageId = $callbackQuery->getMessage()->getMessageId();
    $keyboard = Keyboard::make()->inline();
    $keyboard->row(Keyboard::inlineButton(['text' => "bKash 🔴", 'callback_data' => "add_balance_bkash"]));
    $keyboard->row(Keyboard::inlineButton(['text' => "Nagad 🟠", 'callback_data' => "add_balance_nagad"]));
    editMessageText($chatId, $messageId, "Choose your payment method:", $keyboard);
    answerCallbackQuery($callbackQuery->getId(), "Redirecting to Add Balance section.");
}

function save_trx_id($message) {
    global $data, $balances, $pending_payments, $unmatched_payments;
    $uid = (string) $message->getFrom()->getId();
    $chatId = $message->getChat()->getId();
    $trx = norm_text($message->getText());

    if (!preg_match('/^[A-Za-z0-9]+$/', $trx)) {
        sendMessage($chatId, "❌ Invalid TRX ID format. Please enter a valid Transaction ID.");
        sendMessage($chatId, "⬅️ Back to menu:", main_menu_markup());
        return;
    }

    if (isset($pending_payments[$trx])) {
        sendMessage($chatId, "⏳ This TRX ID is already pending admin confirmation.");
        sendMessage($chatId, "⬅️ Back to menu:", main_menu_markup());
        return;
    }

    $pending_payments[$trx] = $uid;

    if (isset($unmatched_payments[$trx])) {
        $amt = $unmatched_payments[$trx];
        unset($unmatched_payments[$trx]); // Remove from unmatched
        $balances[$uid] = round(($balances[$uid] ?? 0.0) + $amt, 2);
        save_data($data);
        sendMessage($chatId, "✅ Balance added: {$amt} TK (auto-confirmed)");
        sendMessage(ADMIN_ID, "✅ Auto-confirmed TRX `". strtoupper($trx) . "` for user `{$uid}`. Amount: {$amt} TK", null, "Markdown");
    } else {
        save_data($data);
        sendMessage($chatId, "✅ TRX ID received. Awaiting admin confirmation.");
        sendMessage(ADMIN_ID, "💳 *Payment Request*\nTRX ID: `". strtoupper($trx) . "`\nUser ID: `{$uid}`\n\nForward the bKash/Nagad SMS here to confirm.", null, "Markdown");
    }

    sendMessage($chatId, "⬅️ Back to menu:", main_menu_markup());
}

function admin_bkash_nagad_parser($message) {
    global $data, $balances, $pending_payments, $unmatched_payments;
    $txt = $message->getText();
    $trx = parse_trx_id($txt);
    $amt = parse_amount($txt);

    if (!$trx || $amt === null) {
        sendMessage($message->getChat()->getId(), "❌ Could not extract TRX ID or amount from the SMS.", null, "Markdown", $message->getMessageId());
        return;
    }

    if (isset($pending_payments[$trx])) {
        $uid = $pending_payments[$trx];
        unset($pending_payments[$trx]); // Remove from pending
        $balances[$uid] = round(($balances[$uid] ?? 0.0) + $amt, 2);
        save_data($data);
        sendMessage($uid, "✅ Your balance has been topped up: {$amt} TK\nTransaction ID: `". strtoupper($trx) . "`", null, "Markdown");
        sendMessage($message->getChat()->getId(), "✅ Auto-confirmed.\nUser: `{$uid}`\nAmount: {$amt} TK\nTRX: `". strtoupper($trx) . "`", null, "Markdown", $message->getMessageId());
    } elseif (!isset($unmatched_payments[$trx])) {
        $unmatched_payments[$trx] = $amt;
        save_data($data);
        sendMessage($message->getChat()->getId(), "⚠️ SMS saved. No pending user request found for TRX ID: `". strtoupper($trx) . "`. Will auto-confirm when user provides TRX ID.\nAmount: {$amt} TK", null, "Markdown", $message->getMessageId());
    } else {
        sendMessage($message->getChat()->getId(), "ℹ️ This TRX ID `". strtoupper($trx) . "` is already in unmatched payments.", null, "Markdown", $message->getMessageId());
    }
}

// ========== ADMIN FEATURES ==========
function back_to_main_menu_admin($message) {
    sendMessage($message->getChat()->getId(), "Returning to main user menu.", main_menu_markup());
}

function show_total_sales($message) {
    global $total_sales;
    sendMessage($message->getChat()->getId(), sprintf("📈 Total Sales Revenue: %.2f৳", $total_sales), admin_menu_markup());
}

function show_current_stock($message) {
    global $vpn_prices, $products;
    $stock_report = "📦 Current VPN Stock:\n\n";
    $has_stock = false;
    $sorted_vpn_names = array_keys($vpn_prices);
    sort($sorted_vpn_names); // Sort for consistent display

    foreach ($sorted_vpn_names as $vpn_name) {
        $stock_list = $products[$vpn_name] ?? [];
        $stock_report .= "*{$vpn_name}:* " . count($stock_list) . " available\n";
        if (count($stock_list) > 0) {
            $has_stock = true;
        }
    }

    if (!$has_stock) {
        $stock_report .= "No VPNs currently in stock.";
    }

    sendMessage($message->getChat()->getId(), $stock_report, admin_menu_markup(), "Markdown");
}

function ask_add_vpn_account($message) {
    global $vpn_prices;
    $keyboard = Keyboard::make()->inline();
    $sorted_vpn_names = array_keys($vpn_prices);
    sort($sorted_vpn_names); // Sort for consistent display

    foreach ($sorted_vpn_names as $name) {
        $keyboard->row(Keyboard::inlineButton(['text' => $name, 'callback_data' => "admin_add_vpn|{$name}"]));
    }
    sendMessage($message->getChat()->getId(), "Which VPN account do you want to add stock for?", $keyboard);
}

function process_add_vpn_account($message, $vpn_name) {
    global $data, $products, $product_fields;
    $txt = $message->getText();
    $chatId = $message->getChat()->getId();
    $details = [];
    $lines = explode("\n", $txt);

    $required_fields_for_vpn = $product_fields[$vpn_name] ?? ["Gmail", "Password"];
    $parsed_count = 0;

    foreach ($lines as $line) {
        if (str_contains($line, ':')) {
            list($key, $value) = explode(':', $line, 2);
            $standardized_key = str_replace(" ", "_", strtolower(trim($key)));
            $details[$standardized_key] = trim($value);
            $parsed_count++;
        }