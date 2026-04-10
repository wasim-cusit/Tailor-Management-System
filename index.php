<?php
require_once __DIR__ . '/config/functions.php';

$pageTitle = 'درزی مینجمنٹ سسٹم | Tailor Management System';
$trackingResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $trackingCode = trim($_POST['tracking_code'] ?? '');
    if ($trackingCode !== '') {
        $stmt = db()->prepare(
            'SELECT o.order_code, o.tracking_code, o.current_status, o.delivery_date, c.full_name
             FROM orders o
             INNER JOIN customers c ON c.id = o.customer_id
             WHERE o.tracking_code = ?'
        );
        $stmt->execute([$trackingCode]);
        $trackingResult = $stmt->fetch();
    }
}

require __DIR__ . '/includes/header.php';

$contactPhone = appSetting('contact_phone', '+92 300 0000000');
$contactAddress = appSetting('contact_address', 'Your shop address here');
?>
<section class="hero-banner shadow-sm mb-4" id="home">
    <div class="hero-overlay">
        <div class="row g-4 align-items-center">
            <div class="col-lg-7">
                <h1 class="display-6 fw-bold mb-2">Tailor Management System</h1>
                <p class="mb-2 fs-5">درزی مینجمنٹ سسٹم</p>
                <p class="mb-3">Professional stitching workflow with fast tracking, clean records, and easy customer access.</p>
                <p class="mb-0 text-white-50">From measurement to delivery, every step is managed with accuracy. Keep customer profiles, tailor notes, order updates, and payment records in one modern and reliable platform.</p>
            </div>
            <div class="col-lg-5">
                <div class="card border-0 shadow-lg">
                    <div class="card-body p-4">
                        <h2 class="h5" id="track">Track Your Order</h2>
                        <form method="post" class="row g-2">
                            <div class="col-12">
                                <input type="text" name="tracking_code" class="form-control" placeholder="Enter Tracking ID" required>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-primary w-100">Track</button>
                            </div>
                        </form>
                        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
                            <hr>
                            <?php if ($trackingResult): ?>
                                <p class="mb-1"><strong>Customer:</strong> <?= e($trackingResult['full_name']) ?></p>
                                <p class="mb-1"><strong>Order:</strong> <?= e($trackingResult['order_code']) ?></p>
                                <p class="mb-1"><strong>Status:</strong> <?= e($trackingResult['current_status']) ?></p>
                                <p class="mb-0"><strong>Delivery:</strong> <?= e($trackingResult['delivery_date']) ?></p>
                            <?php else: ?>
                                <p class="text-danger mb-0">Tracking code not found.</p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="row g-3 align-items-center">
            <div class="col-lg-6">
                <h2 class="h4 mb-2">Why Choose Our Tailor Service</h2>
                <p class="text-muted mb-3">We combine traditional tailoring expertise with digital management. This helps us maintain exact measurements, reduce delivery delays, and provide transparent order tracking for every customer.</p>
                <ul class="mb-0 text-muted">
                    <li>Precise measurement records for every repeat order</li>
                    <li>Live order status from cutting to delivery</li>
                    <li>Clear payment, balance, and ledger history</li>
                    <li>Responsive support for urgent stitching requests</li>
                </ul>
            </div>
            <div class="col-lg-6">
                <div class="feature-visual shadow-sm">
                    <div class="feature-visual-overlay">
                        <div class="feature-chip">Premium Stitching</div>
                        <h3 class="h5 mb-2">Crafted With Detail</h3>
                        <p class="mb-0">Every order is handled by skilled tailors with quality checks at each stage.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm" id="services">
    <div class="card-body">
        <h2 class="h5 mb-3">Services</h2>
        <div class="row row-cols-1 row-cols-md-3 g-3">
            <div class="col"><div class="p-3 border rounded h-100">Custom Kameez & Shalwar Stitching</div></div>
            <div class="col"><div class="p-3 border rounded h-100">Measurements & Design Preferences</div></div>
            <div class="col"><div class="p-3 border rounded h-100">Fast Tracking & Delivery Updates</div></div>
        </div>
    </div>
</div>

<div class="row g-4 mt-1">
    <div class="col-lg-4 col-md-6">
        <div class="card shadow-sm h-100">
            <img src="https://images.unsplash.com/photo-1556905055-8f358a7a47b2?auto=format&fit=crop&w=900&q=80" class="card-img-top home-gallery-img" alt="Fabric collection">
            <div class="card-body">
                <h3 class="h6">Premium Fabric Handling</h3>
                <p class="text-muted mb-0">Careful cutting and finishing for cotton, wash & wear, and formal fabrics.</p>
            </div>
        </div>
    </div>
    <div class="col-lg-4 col-md-6">
        <div class="card shadow-sm h-100">
            <img src="https://images.unsplash.com/photo-1512436991641-6745cdb1723f?auto=format&fit=crop&w=900&q=80" class="card-img-top home-gallery-img" alt="Custom stitched outfit">
            <div class="card-body">
                <h3 class="h6">Custom Fitting Excellence</h3>
                <p class="text-muted mb-0">Design details like collar, cuff, and front style tailored exactly to your choice.</p>
            </div>
        </div>
    </div>
    <div class="col-lg-4 col-md-12">
        <div class="card shadow-sm h-100">
            <img src="https://images.unsplash.com/photo-1521572163474-6864f9cf17ab?auto=format&fit=crop&w=900&q=80" class="card-img-top home-gallery-img" alt="Delivery ready stitched clothes">
            <div class="card-body">
                <h3 class="h6">On-Time Delivery Promise</h3>
                <p class="text-muted mb-0">Production tracking helps us deliver your order on time with quality assurance.</p>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mt-4">
    <div class="card-body">
        <h2 class="h5 mb-3">How It Works</h2>
        <div class="row g-3 row-cols-1 row-cols-md-5">
            <div class="col"><div class="process-step"><span>1</span><p>Order Placed</p></div></div>
            <div class="col"><div class="process-step"><span>2</span><p>Cutting</p></div></div>
            <div class="col"><div class="process-step"><span>3</span><p>Stitching</p></div></div>
            <div class="col"><div class="process-step"><span>4</span><p>Ready</p></div></div>
            <div class="col"><div class="process-step"><span>5</span><p>Delivered</p></div></div>
        </div>
    </div>
</div>

<div class="card shadow-sm mt-4" id="contact">
    <div class="card-body">
        <h2 class="h5 mb-2">Contact</h2>
        <p class="text-muted mb-3">For new orders, alteration requests, or urgent delivery, contact us directly. Our team is available to guide you about styles, fitting, and order timelines.</p>
        <div class="row g-3">
            <div class="col-md-6">
                <div class="p-3 border rounded h-100">
                    <div class="fw-semibold mb-1">Phone</div>
                    <div class="text-muted"><?= e($contactPhone) ?></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-3 border rounded h-100">
                    <div class="fw-semibold mb-1">Address</div>
                    <div class="text-muted"><?= e($contactAddress) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

