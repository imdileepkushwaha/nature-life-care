<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="description"
        content="Nature Life Care offers natural nutrition, wellness education, and business opportunities centered on healthy living and Ayurveda-inspired solutions." />
    <title><?= !empty($pageTitle) ? htmlspecialchars($pageTitle) . ' | Nature Life Care' : 'Nature Life Care | Nourishing Life Naturally' ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
        href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.3.1/css/all.min.css"
        crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="styles.css" />
</head>

<body>
    <header class="site-header">
        <div class="container header-inner">
            <a href="index.php" class="brand" aria-label="Nature Life Care home">
                <img src="Images/logo.png" alt="Nature Life Care logo" />
            </a>

            <button class="nav-toggle" aria-label="Toggle menu">
                <span></span>
                <span></span>
                <span></span>
            </button>

            <nav class="main-nav" aria-label="Main navigation">
                <a href="index.php#about">About</a>
                <a href="products.php">Products</a>
                <a href="index.php#wellness">Wellness</a>
                <a href="index.php#business">Business</a>
                <a href="index.php#testimonials">Testimonials</a>
                <a href="contact.php">Contact</a>
                <a href="user/login.php">Login</a>
            </nav>

            <a href="user/register.php" class="btn btn-primary header-btn">Join Us</a>
        </div>
    </header>
