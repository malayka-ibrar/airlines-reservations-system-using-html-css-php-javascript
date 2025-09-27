<?php
// Include FPDF library
require_once('fpdf.php');

// --- Setup DB credentials ---
$host = 'localhost';
$db = 'airline_reservation';
$user = 'root';  // Change if necessary
$pass = '';      // Change if necessary
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (Exception $e) {
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}

function safe($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// User authentication: login, signup, logout handling
if (!isset($_SESSION)) session_start();

if (isset($_POST['logout'])) {
    session_destroy();
    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        header("Location: ".$_SERVER['PHP_SELF']);
        exit;
    } else {
        $loginMessage = '<span class="error">Invalid username or password.</span>';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['signup'])) {
    $username = trim($_POST['signup_username']);
    $email = trim($_POST['signup_email']);
    $password = password_hash(trim($_POST['signup_password']), PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
    try {
        $stmt->execute([$username, $email, $password]);
        $_SESSION['user_id'] = $pdo->lastInsertId();
        $_SESSION['username'] = $username;
        header("Location: ".$_SERVER['PHP_SELF']);
        exit;
    } catch (Exception $e) {
        $signupMessage = '<span class="error">Username or email already exists.</span>';
    }
}

$searchDeparture = $_GET['departure'] ?? '';
$searchDestination = $_GET['destination'] ?? '';
$searchDate = $_GET['departure_date'] ?? '';

$where = [];
$params = [];
if ($searchDeparture !== '') {
    $where[] = "departure LIKE ?";
    $params[] = "%$searchDeparture%";
}
if ($searchDestination !== '') {
    $where[] = "destination LIKE ?";
    $params[] = "%$searchDestination%";
}
if ($searchDate !== '') {
    $where[] = "departure_date = ?";
    $params[] = $searchDate;
}
$where[] = "(seats_economy + seats_business + seats_first) > 0";

$whereSql = count($where) ? "WHERE " . implode(" AND ", $where) : "";

$sql = "SELECT * FROM flights $whereSql ORDER BY departure_date, departure_time";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$flights = $stmt->fetchAll();

$bookingMessage = "";
$paymentMessage = "";
$booking_id = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['book'])) {
        $flight_id = intval($_POST['flight_id']);
        $passenger_name = trim($_POST['passenger_name']);
        $seat_class = $_POST['seat_class'];

        if ($passenger_name === '') {
            $bookingMessage = '<span class="error">Please enter your name.</span>';
        } else if (!in_array($seat_class, ['economy', 'business', 'first'])) {
            $bookingMessage = '<span class="error">Invalid seat class selected.</span>';
        } else {
            $seat_column = 'seats_' . $seat_class;
            $stmt = $pdo->prepare("SELECT $seat_column FROM flights WHERE id = ? FOR UPDATE");
            $pdo->beginTransaction();
            try {
                $stmt->execute([$flight_id]);
                $seat_avail = $stmt->fetchColumn();
                if ($seat_avail && $seat_avail > 0) {
                    $insert = $pdo->prepare("INSERT INTO bookings (flight_id, passenger_name, seat_class) VALUES (?, ?, ?)");
                    $insert->execute([$flight_id, $passenger_name, $seat_class]);
                    $booking_id = $pdo->lastInsertId();

                    $update = $pdo->prepare("UPDATE flights SET $seat_column = $seat_column - 1 WHERE id = ?");
                    $update->execute([$flight_id]);
                    $pdo->commit();
                    $bookingMessage = '<span class="success">Booking successful! Your Booking ID is <b>' . safe($booking_id) . '</b>. Please proceed to payment below.</span>';

                    $_SESSION['booking_id'] = $booking_id;
                    $_SESSION['seat_class'] = $seat_class;
                    unset($_SESSION['payment_done']); // Reset payment status to false on new booking
                } else {
                    $pdo->rollBack();
                    $bookingMessage = '<span class="error">Selected seat class is not available.</span>';
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $bookingMessage = '<span class="error">Booking failed. Please try again later.</span>';
            }
        }
    } elseif (isset($_POST['pay'])) {
        $booking_id = intval($_POST['booking_id']);
        $payment_method = $_POST['payment_method'];
        $card_number = trim($_POST['card_number']);
        $pin = trim($_POST['pin']);
        
        if (empty($card_number) || empty($pin)) {
            $paymentMessage = '<span class="error">Please enter card number and PIN.</span>';
            $bookingMessage = '<span class="success">Booking successful! Your Booking ID is <b>' . safe($booking_id) . '</b>. Please complete your payment below.</span>';
        } else {
            $stmt = $pdo->prepare("SELECT f.price_economy, f.price_business, f.price_first, b.seat_class FROM bookings b JOIN flights f ON b.flight_id = f.id WHERE b.id = ?");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch();

            if ($booking) {
                $amount = $booking['seat_class'] === 'economy' ? $booking['price_economy'] : ($booking['seat_class'] === 'business' ? $booking['price_business'] : $booking['price_first']);

                $insert = $pdo->prepare("INSERT INTO payments (booking_id, payment_method, card_number, pin, amount) VALUES (?, ?, ?, ?, ?)");
                try {
                    $insert->execute([$booking_id, $payment_method, $card_number, $pin, $amount]);
                    $_SESSION['payment_done'] = true;
                    $paymentMessage = '<span class="success">Payment successful! Your payment ID is <b>' . $pdo->lastInsertId() . '</b>. Your Booking ID is <b>' . safe($booking_id) . '</b>. Please click the button below to download your ticket.</span>';
                    $bookingMessage = '';
                } catch (Exception $e) {
                    $paymentMessage = '<span class="error">Payment failed. Please try again.</span>';
                    $bookingMessage = '<span class="success">Booking successful! Your Booking ID is <b>' . safe($booking_id) . '</b>. Please complete your payment below.</span>';
                }
            } else {
                $paymentMessage = '<span class="error">Booking not found.</span>';
                $bookingMessage = '<span class="success">Booking successful! Your Booking ID is <b>' . safe($booking_id) . '</b>. Please complete your payment below.</span>';
            }
        }
    }
}

$upgradeMessage = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upgrade'])) {
    $booking_id = intval($_POST['booking_id']);
    $new_class = $_POST['new_class'];

    if (!in_array($new_class, ['economy', 'business', 'first'])) {
        $upgradeMessage = '<span class="error">Invalid class selected for upgrade.</span>';
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? FOR UPDATE");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch();
            if (!$booking) {
                $pdo->rollBack();
                $upgradeMessage = '<span class="error">Booking not found.</span>';
            } else {
                $current_class = $booking['seat_class'];
                if ($new_class === $current_class) {
                    $pdo->rollBack();
                    $upgradeMessage = '<span class="error">You are already in this class.</span>';
                } else {
                    $seat_column_new = 'seats_' . $new_class;
                    $seat_column_current = 'seats_' . $current_class;
                    $stmt2 = $pdo->prepare("SELECT $seat_column_new FROM flights WHERE id = ? FOR UPDATE");
                    $stmt2->execute([$booking['flight_id']]);
                    $available_new = $stmt2->fetchColumn();

                    if ($available_new > 0) {
                        $updateBook = $pdo->prepare("UPDATE bookings SET seat_class = ? WHERE id = ?");
                        $updateBook->execute([$new_class, $booking_id]);

                        $updateSeats = $pdo->prepare("UPDATE flights SET $seat_column_current = $seat_column_current + 1, $seat_column_new = $seat_column_new - 1 WHERE id = ?");
                        $updateSeats->execute([$booking['flight_id']]);
                        $pdo->commit();
                        $upgradeMessage = '<span class="success">Upgrade successful!</span>';
                    } else {
                        $pdo->rollBack();
                        $upgradeMessage = '<span class="error">No seats available in the selected upgrade class.</span>';
                    }
                }
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $upgradeMessage = '<span class="error">Upgrade failed. Please try again later.</span>';
        }
    }
}

$cancelMessage = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel'])) {
    $booking_id = intval($_POST['booking_id']);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? FOR UPDATE");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch();
        if (!$booking) {
            $pdo->rollBack();
            $cancelMessage = '<span class="error">Booking not found.</span>';
        } else {
            $seat_class = $booking['seat_class'];
            $seat_column = 'seats_' . $seat_class;

            $delete = $pdo->prepare("DELETE FROM bookings WHERE id = ?");
            $delete->execute([$booking_id]);

            $updateSeats = $pdo->prepare("UPDATE flights SET $seat_column = $seat_column + 1 WHERE id = ?");
            $updateSeats->execute([$booking['flight_id']]);

            $pdo->commit();
            $cancelMessage = '<span class="success">Booking cancelled successfully.</span>';

            if (isset($_SESSION['booking_id']) && $_SESSION['booking_id'] == $booking_id) {
                unset($_SESSION['booking_id']);
                unset($_SESSION['payment_done']);
            }
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $cancelMessage = '<span class="error">Cancellation failed. Please try again later.</span>';
    }
}

if (isset($_GET['download_ticket']) && isset($_SESSION['booking_id']) && !empty($_SESSION['payment_done'])) {
    $bid = $_SESSION['booking_id'];
    $stmt = $pdo->prepare("SELECT b.id AS booking_id, b.passenger_name, b.seat_class, f.flight_number, f.departure, f.destination, f.departure_date, f.departure_time,
          f.price_economy, f.price_business, f.price_first
        FROM bookings b JOIN flights f ON b.flight_id = f.id WHERE b.id = ?");
    $stmt->execute([$bid]);
    $ticket = $stmt->fetch();

    if ($ticket) {
        generateTicketPDF($ticket);
        exit;
    } else {
        echo "Ticket not found.";
        exit;
    }
}

function generateTicketPDF($ticket) {
    $pdf = new FPDF();
    $pdf->AddPage();

    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, 'Airline Reservation Ticket', 0, 1, 'C');
    $pdf->Ln(5);

    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(50, 10, 'Booking ID:', 0, 0);
    $pdf->Cell(0, 10, $ticket['booking_id'], 0, 1);

    $pdf->Cell(50, 10, 'Passenger Name:', 0, 0);
    $pdf->Cell(0, 10, $ticket['passenger_name'], 0, 1);

    $pdf->Cell(50, 10, 'Seat Class:', 0, 0);
    $pdf->Cell(0, 10, ucfirst($ticket['seat_class']), 0, 1);

    $pdf->Ln(5);

    $pdf->Cell(50, 10, 'Flight Number:', 0, 0);
    $pdf->Cell(0, 10, $ticket['flight_number'], 0, 1);

    $pdf->Cell(50, 10, 'Departure:', 0, 0);
    $pdf->Cell(0, 10, $ticket['departure'], 0, 1);

    $pdf->Cell(50, 10, 'Destination:', 0, 0);
    $pdf->Cell(0, 10, $ticket['destination'], 0, 1);

    $pdf->Cell(50, 10, 'Departure Date:', 0, 0);
    $pdf->Cell(0, 10, $ticket['departure_date'], 0, 1);

    $pdf->Cell(50, 10, 'Departure Time:', 0, 0);
    $pdf->Cell(0, 10, $ticket['departure_time'], 0, 1);

    $pdf->Ln(10);

    $price_field = 'price_' . $ticket['seat_class'];
    $price = $ticket[$price_field];

    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, "Price Paid: $" . number_format($price, 2), 0, 1, 'C');

    $pdf->Output('D', 'Ticket_' . $ticket['booking_id'] . '.pdf');
}

// Handle feedback form submit
$feedbackMessage = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
    $fb_name = trim($_POST['fb_name'] ?? '');
    $fb_rating = intval($_POST['fb_rating'] ?? 0);
    $fb_comment = trim($_POST['fb_comment'] ?? '');

    if ($fb_name === '' || $fb_rating < 1 || $fb_rating > 5 || $fb_comment === '') {
        $feedbackMessage = '<span class="error">Please fill all fields properly.</span>';
    } else {
        $stmt = $pdo->prepare("INSERT INTO reviews (name, rating, comment, created_at) VALUES (?, ?, ?, NOW())");
        try {
            $stmt->execute([$fb_name, $fb_rating, $fb_comment]);
            $feedbackMessage = '<span class="success">Thank you for your feedback!</span>';
        } catch (Exception $e) {
            $feedbackMessage = '<span class="error">Failed to submit feedback. Please try again later.</span>';
        }
    }
}

// Fetch existing feedbacks with limit for display
$totalFeedbacks = $pdo->query("SELECT COUNT(DISTINCT comment) FROM reviews")->fetchColumn();
$feedbacks = $pdo->query("SELECT DISTINCT * FROM reviews ORDER BY created_at DESC LIMIT 3")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Airline Reservation System</title>
    <link rel="stylesheet" href="style.css">
    <script src="script.js" defer></script>
</head>
<body>
<div class="header-container">
    <img src="https://th.bing.com/th/id/OIP.3fKUxovLUasLQ9-4w4zM9QAAAA?rs=1&pid=ImgDetMain" alt="" class="logo" style="width: 100px; height: 100px;" />
    <h1>Airline Reservation System</h1>
    <?php if (isset($_SESSION['username'])): ?>
    <span style="margin-left: auto; font-weight: 600;">Welcome, <?= safe($_SESSION['username']) ?>!</span>
    <form method="post" action="" style="margin-left: 10px;">
        <button type="submit" name="logout">Logout</button>
    </form>
<?php else: ?>
    <button id="loginBtn" style="margin-left: auto;">Login</button>
    <button id="signupBtn">Sign Up</button>
<?php endif; ?>
</div>

<!-- Login Modal -->
<div id="loginModal" class="modal" style="display:none;">
    <div class="modal-content">
        <span class="close" id="loginClose">&times;</span>
        <form method="post" action="">
            <h2>Login</h2>
            <label for="username">Username</label>
            <input type="text" id="username" name="username" required />
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required />
            <button type="submit" name="login">Log In</button>
            <?php if (!empty($loginMessage)) echo $loginMessage; ?>
        </form>
    </div>
</div>

<!-- Sign Up Modal -->
<div id="signupModal" class="modal" style="display:none;">
    <div class="modal-content">
        <span class="close" id="signupClose">&times;</span>
        <form method="post" action="">
            <h2>Sign Up</h2>
            <label for="signup_username">Username</label>
            <input type="text" id="signup_username" name="signup_username" required />
            <label for="signup_email">Email</label>
            <input type="email" id="signup_email" name="signup_email" required />
            <label for="signup_password">Password</label>
            <input type="password" id="signup_password" name="signup_password" required />
            <button type="submit" name="signup">Sign Up</button>
            <?php if (!empty($signupMessage)) echo $signupMessage; ?>
        </form>
    </div>
</div>

<!-- Flight Search Form -->
<form method="get" action="">
    <fieldset style="border:none;">
        <legend style="font-weight: bold; font-size: 1.2em; margin-bottom: 10px;">Search Flights</legend>
        <div class="flex-row">
            <div class="flex-grow">
                <label for="departure">Departure</label>
                <input type="text" id="departure" name="departure" placeholder="e.g. Pakistan" value="<?= safe($searchDeparture) ?>" />
            </div>
            <div class="flex-grow">
                <label for="destination">Destination</label>
                <input type="text" id="destination" name="destination" placeholder="e.g. United States" value="<?= safe($searchDestination) ?>" />
            </div>
            <div class="flex-grow">
                <label for="departure_date">Date</label>
                <input type="date" id="departure_date" name="departure_date" value="<?= safe($searchDate) ?>" />
            </div>
        </div>
        <button type="submit">Search</button>
    </fieldset>
</form>

<h2>Available Flights</h2>
<?php if (count($flights) === 0): ?>
    <p>No available flights match your search criteria.</p>
<?php else: ?>
<table>
<thead>
<tr>
    <th>#</th>
    <th>Flight Number</th>
    <th>Departure</th>
    <th>Destination</th>
    <th>Date</th>
    <th>Time</th>
    <th>Economy Seats</th>
    <th>Business Seats</th>
    <th>First Class Seats</th>
    <th>Prices (E/B/F)</th>
    <th>Book</th>
</tr>
</thead>
<tbody>
<?php foreach ($flights as $idx => $flight): ?>
<tr class="<?= $idx >= 4 ? 'hidden-flights' : '' ?>">
    <td><?= $idx + 1 ?></td>
    <td><?= safe($flight['flight_number']) ?></td>
    <td><?= safe($flight['departure']) ?></td>
    <td><?= safe($flight['destination']) ?></td>
    <td><?= safe($flight['departure_date']) ?></td>
    <td><?= safe(substr($flight['departure_time'], 0, 5)) ?></td>
    <td><?= safe($flight['seats_economy']) ?></td>
    <td><?= safe($flight['seats_business']) ?></td>
    <td><?= safe($flight['seats_first']) ?></td>
    <td>$<?= number_format($flight['price_economy'], 2) ?> / $<?= number_format($flight['price_business'], 2) ?> / $<?= number_format($flight['price_first'], 2) ?></td>
    <td>
        <form method="post" action="" style="display:inline-block;">
            <input type="hidden" name="flight_id" value="<?= safe($flight['id']) ?>" />
            <select name="seat_class" required>
                <?php if ($flight['seats_economy'] > 0): ?>
                    <option value="economy">Economy</option>
                <?php endif; ?>
                <?php if ($flight['seats_business'] > 0): ?>
                    <option value="business">Business</option>
                <?php endif; ?>
                <?php if ($flight['seats_first'] > 0): ?>
                    <option value="first">First Class</option>
                <?php endif; ?>
            </select>
            <input type="text" name="passenger_name" placeholder="Your Name" required style="width:140px; margin-top:5px;" />
            <button type="submit" name="book">Book</button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php if (count($flights) > 4): ?>
    <button id="showMoreBtnAvailable">Show More Flights</button>
<?php endif; ?>
<?php endif; ?>

<?php if ($bookingMessage): ?>
<div><?= $bookingMessage ?></div>
<?php endif; ?>

<?php if ($bookingMessage && empty($_SESSION['payment_done'])): ?>
<div id="paymentModal" class="modal" style="display:block;">
    <div class="modal-content">
        <span class="close" id="paymentClose">&times;</span>
        <form method="post" action="">
            <h2>Select Payment Method</h2>
            <input type="hidden" name="booking_id" value="<?= safe($booking_id ?? ($_SESSION['booking_id'] ?? '')) ?>" />
            <label for="payment_method">Payment Method</label>
            <select id="payment_method" name="payment_method" required>
                <option value="Credit Card" <?= (isset($_POST['payment_method']) && $_POST['payment_method'] == 'Credit Card') ? 'selected' : '' ?>>Credit Card</option>
                <option value="Debit Card" <?= (isset($_POST['payment_method']) && $_POST['payment_method'] == 'Debit Card') ? 'selected' : '' ?>>Debit Card</option>
                <option value="PayPal" <?= (isset($_POST['payment_method']) && $_POST['payment_method'] == 'PayPal') ? 'selected' : '' ?>>PayPal</option>
            </select>
            <label for="card_number">Card Number</label>
            <input type="text" id="card_number" name="card_number" required value="<?= isset($_POST['card_number']) ? safe($_POST['card_number']) : '' ?>" />
            <label for="pin">PIN</label>
            <input type="password" id="pin" name="pin" required value="<?= isset($_POST['pin']) ? safe($_POST['pin']) : '' ?>" />
            <button type="submit" name="pay">Confirm Payment</button>
            <?php if (!empty($paymentMessage)) echo $paymentMessage; ?>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['booking_id']) && !empty($_SESSION['payment_done'])): ?>
    <form method="get" action="">
        <button type="submit" name="download_ticket">Download Your Ticket PDF</button>
    </form>
<?php endif; ?>

<!-- Upgrade Booking -->
<h2>Upgrade Booking</h2>
<form method="post" action="">
    <label for="booking_id_upgrade">Booking ID</label>
    <input type="number" id="booking_id_upgrade" name="booking_id" required placeholder="Enter your Booking ID" />
    <label for="new_class">Upgrade to Class</label>
    <select id="new_class" name="new_class" required>
        <option value="economy">Economy</option>
        <option value="business">Business</option>
        <option value="first">First Class</option>
    </select>
    <button type="submit" name="upgrade">Upgrade</button>
</form>
<?php if ($upgradeMessage): ?>
<div><?= $upgradeMessage ?></div>
<?php endif; ?>

<!-- Cancel Booking -->
<h2>Cancel Booking</h2>
<form method="post" action="">
    <label for="booking_id_cancel">Booking ID</label>
    <input type="number" id="booking_id_cancel" name="booking_id" required placeholder="Enter your Booking ID" />
    <button type="submit" name="cancel">Cancel Booking</button>
</form>
<?php if ($cancelMessage): ?>
<div><?= $cancelMessage ?></div>
<?php endif; ?>

<!-- Show All Flights Table -->
<h2>All Flights</h2>
<?php
$allFlights = $pdo->query("SELECT * FROM flights ORDER BY departure_date, departure_time")->fetchAll();
$totalFlights = count($allFlights);
?>
<table>
<thead>
<tr>
    <th>Flight Number</th>
    <th>Departure</th>
    <th>Destination</th>
    <th>Date</th>
    <th>Time</th>
    <th>Economy Seats</th>
    <th>Business Seats</th>
    <th>First Class Seats</th>
    <th>Prices (E/B/F)</th>
</tr>
</thead>
<tbody>
<?php foreach ($allFlights as $index => $flight): ?>
<tr class="<?= $index >= 4 ? 'hidden-flights' : '' ?>">
    <td><?= safe($flight['flight_number']) ?></td>
    <td><?= safe($flight['departure']) ?></td>
    <td><?= safe($flight['destination']) ?></td>
    <td><?= safe($flight['departure_date']) ?></td>
    <td><?= safe(substr($flight['departure_time'], 0, 5)) ?></td>
    <td><?= safe($flight['seats_economy']) ?></td>
    <td><?= safe($flight['seats_business']) ?></td>
    <td><?= safe($flight['seats_first']) ?></td>
    <td>$<?= number_format($flight['price_economy'], 2) ?> / $<?= number_format($flight['price_business'], 2) ?> / $<?= number_format($flight['price_first'], 2) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php if ($totalFlights > 4): ?>
    <button id="showMoreBtnAll">Show More Flights</button>
<?php endif; ?>

<div id="feedback-section">
    <h2>Customer Feedback & Reviews</h2>
    <?php if ($feedbackMessage): ?>
        <div><?= $feedbackMessage ?></div>
    <?php endif; ?>
    <form id="feedback-form" method="post" action="">
        <label for="fb_name">Your Name</label>
        <input type="text" id="fb_name" name="fb_name" required />

        <label for="fb_rating">Rating</label>
        <select id="fb_rating" name="fb_rating" required>
            <option value="" disabled selected>Select rating</option>
            <option value="5">5 - Excellent</option>
            <option value="4">4 - Good</option>
            <option value="3">3 - Average</option>
            <option value="2">2 - Poor</option>
            <option value="1">1 - Very Poor</option>
        </select>

        <label for="fb_comment">Your Feedback</label>
        <textarea id="fb_comment" name="fb_comment" rows="4" required></textarea>

        <button type="submit" name="submit_feedback">Submit Feedback</button>
    </form>

    <?php if (count($feedbacks) > 0): ?>
        <h3 style="margin-top:30px; color:#0077cc;">Recent Feedback</h3>
        <?php foreach ($feedbacks as $fb): ?>
            <div class="feedback-item">
                <h4><?= safe($fb['name']) ?></h4>
                <div class="feedback-rating">Rating: <?= safe(str_repeat('★', $fb['rating'])) . str_repeat('☆', 5 - $fb['rating']) ?></div>
                <div class="feedback-comment"><?= nl2br(safe($fb['comment'])) ?></div>
                <div class="feedback-date"><?= date('F j, Y, g:i a', strtotime($fb['created_at'])) ?></div>
            </div>
        <?php endforeach; ?>
        <?php if ($totalFeedbacks > 3): ?>
            <form method="get" action="" style="text-align:center;">
                <button type="submit" id="showMoreFeedbackBtn">Show More Reviews</button>
            </form>
        <?php endif; ?>
    <?php else: ?>
        <p>No feedback yet. Be the first to share your thoughts!</p>
    <?php endif; ?>
</div>

<footer class="footer">
  &copy; <?= date('Y') ?> CopyRight| Made by Malayka Ibrar and Maleeha Zafar 
</footer>

<script src="script.js"></script>
</body>
</html>