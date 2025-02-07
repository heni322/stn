<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;
use App\Models\Product;
use App\Models\Site;
use App\Models\Category;
use Spatie\Browsershot\Browsershot;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ScrapeProducts extends Command
{
    protected $signature = 'app:scrape-products';
    protected $description = 'Enhanced scraper with maximum reliability';

    protected $categories = [
        'Vêtements' => 'https://fr.shein.com/Women-Clothing-c-2030.html',
    ];

    protected $progressBar;
    protected $categoryProgressBar;
    protected $totalProductsFound = 0;
    protected $startTime;
    protected $lastStatusUpdate;
    protected $statusUpdateInterval = 60;
    protected $maxRetries = 3;
    protected $retryDelay = 3;
    protected $sessionDuration = 120; // 5 minutes
    protected $currentProxy = null;
    protected $proxyFailures = [];
    protected $maxProxyFailures = 3;

    protected $userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36'
    ];

    protected $popupSelectors = [
        'closeButtons' => [
            'button[aria-label="Close"]',
            'button[aria-label="Fermer"]',
            '.modal-close',
            '.popup-close',
            '.close-button',
            '.privacy-dialog-close',
            '[data-dismiss="modal"]',
            '.drawer__close',
            '.toast-close-button',
            '.notification-close',
            '[class*="close"]',
            '[class*="dismiss"]'
        ],
        'cookieButtons' => [
            '[id*="cookie"] button',
            '[class*="cookie"] button',
            '#accept-cookies',
            '#cookie-accept',
            '.cookie-consent-accept',
            '[aria-label*="cookie"] button',
            '[data-purpose*="cookie"] button',
            '.cc-accept',
            '.accept-cookies-button'
        ],
        'newsletterButtons' => [
            '[id*="newsletter"] button',
            '[class*="newsletter"] button',
            '#newsletter-close',
            '.newsletter-popup-close',
            '[class*="signup"] .close',
            '[class*="subscribe"] .close',
            '[aria-label*="newsletter"] .close'
        ],
        'overlayElements' => [
            '.modal-backdrop',
            '.overlay',
            '.popup-overlay',
            '[class*="overlay"]',
            '[class*="backdrop"]',
            '.drawer-backdrop',
            '.modal-background'
        ]
    ];

    protected $productSelectors = [
        'name' => [
            '.product-intro__head-name',
            '[data-product-name]',
            '.product-name',
            '[class*="product"][class*="name"]',
            '[class*="title"]'
        ],
        'price' => [
            '.product-intro__head-price',
            '[data-product-price]',
            '.product-price',
            '[class*="product"][class*="price"]',
            '[class*="price"]'
        ],
        'description' => [
            '.product-intro__description',
            '[data-product-description]',
            '.product-description',
            '[class*="product"][class*="description"]',
            '[class*="description"]'
        ],
        'image' => [
            '.product-intro__image img',
            '[data-product-image]',
            '.product-image img',
            '[class*="product"][class*="image"] img',
            '[class*="gallery"] img'
        ]
    ];

    protected $browserProfiles = [
        [
            'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
            'platform' => 'Windows',
            'viewport' => ['width' => 1920, 'height' => 1080],
            'languages' => ['fr-FR', 'fr', 'en-US'],
        ],
        [
            'userAgent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
            'platform' => 'MacOS',
            'viewport' => ['width' => 1440, 'height' => 900],
            'languages' => ['fr-FR', 'fr', 'en-US'],
        ],
    ];


    public function handle()
    {
        try {
            $this->startTime = now();
            $this->lastStatusUpdate = now();

            $this->info('Starting scraping process...');
            $this->line('');
            $this->line('🔍 Initializing scraper configuration...');

            $site = Site::firstOrCreate(
                ['name' => 'Shein FR'],
                ['url' => 'https://fr.shein.com']
            );

            $totalCategories = count($this->categories);
            $this->categoryProgressBar = $this->output->createProgressBar($totalCategories);
            $this->categoryProgressBar->setFormat(
                '▕%bar%▏ %current%/%max% categories [%message%]'
            );

            foreach ($this->categories as $categoryName => $categoryUrl) {
                $this->categoryProgressBar->setMessage("Processing: $categoryName");

                try {
                    $category = Category::firstOrCreate(['name' => $categoryName]);
                    $this->scrapeCategory($categoryUrl, $site, $category);

                    $this->categoryProgressBar->advance();

                    // Display statistics
                    $this->displayScrapingStatistics();

                } catch (\Exception $e) {
                    $this->error("Error processing category $categoryName: " . $e->getMessage());
                    Log::error("Category scraping error", [
                        'category' => $categoryName,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    continue;
                }
            }

            $this->categoryProgressBar->finish();
            $this->line('');
            $this->displayFinalStatistics();

        } catch (\Exception $e) {
            $this->error('Fatal error in scraping process: ' . $e->getMessage());
            Log::error("Fatal scraping error", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    protected function displayScrapingStatistics()
    {
        if (now()->diffInSeconds($this->lastStatusUpdate) < $this->statusUpdateInterval) {
            return;
        }

        $this->line('');
        $this->info('📊 Current Statistics:');
        $this->line("Total products found: {$this->totalProductsFound}");
        $this->line("Time elapsed: " . $this->startTime->diffForHumans(null, true));
        $this->line("Products per minute: " . round($this->totalProductsFound / max(1, $this->startTime->diffInMinutes(now())), 2));
        $this->line('');

        $this->lastStatusUpdate = now();
    }

    protected function displayFinalStatistics()
    {
        $duration = $this->startTime->diffForHumans(null, true);
        $productsPerMinute = round($this->totalProductsFound / max(1, $this->startTime->diffInMinutes(now())), 2);

        $this->line('');
        $this->info('🎉 Scraping Complete!');
        $this->line('');
        $this->line("📊 Final Statistics:");
        $this->line("├─ Total products scraped: {$this->totalProductsFound}");
        $this->line("├─ Total time: {$duration}");
        $this->line("├─ Average speed: {$productsPerMinute} products/minute");
        $this->line("└─ Memory peak: " . $this->formatBytes(memory_get_peak_usage(true)));
    }

    protected function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        return round($bytes / pow(1024, $pow), 2) . ' ' . $units[$pow];
    }

    protected function getFreeProxies() {
        $proxies = [];

        // ProxyScrape API (free tier)
        $response = file_get_contents('https://api.proxyscrape.com/v2/?request=getproxies&protocol=http&timeout=10000&country=all&ssl=all&anonymity=all');
        if ($response) {
            $proxies = array_merge($proxies, explode("\n", $response));
        }

        // Alternative source
        $response = file_get_contents('https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/http.txt');
        if ($response) {
            $proxies = array_merge($proxies, explode("\n", $response));
        }

        return array_filter($proxies); // Remove empty entries
    }

    protected function testProxy($proxy) {
        try {
            $client = new Client([
                'proxy' => $proxy,
                'timeout' => 5,
                'verify' => false
            ]);

            $response = $client->get('https://api.myip.com/');
            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function rotateProxy() {
        $proxies = $this->getFreeProxies();
        $workingProxy = null;

        foreach ($proxies as $proxy) {
            if ($this->testProxy($proxy)) {
                $workingProxy = $proxy;
                break;
            }
        }

        if (!$workingProxy) {
            throw new \Exception("No working proxies found");
        }

        $this->currentProxy = $workingProxy;
        $this->info("Rotating to new proxy: " . $this->maskProxy($this->currentProxy));
        sleep(rand(3, 5));
    }

    private function getRandomUserAgent()
    {
        return $this->userAgents[array_rand($this->userAgents)];
    }

    private function getBrowsershot($url)
    {
        if (!$this->currentProxy) {
            $this->rotateProxy();
        }

        // Select random browser profile
        $profile = $this->browserProfiles[array_rand($this->browserProfiles)];

        $browsershot = Browsershot::url($url)
            ->setChromePath('C:\Program Files\Google\Chrome\Application\chrome.exe')
            ->setProxyServer($this->currentProxy)
            ->ignoreHttpsErrors()
            ->setExtraHttpHeaders([
                'User-Agent' => $this->getRandomUserAgent(),
                'Accept-Language' => 'fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Connection' => 'keep-alive',
                'Cache-Control' => 'max-age=0'
            ])
            ->dismissDialogs()
            ->setDelay(rand(1000, 2000))
            ->windowSize(rand(1024, 1920), rand(768, 1080))
            ->setOption('args', [
                '--disable-blink-features=AutomationControlled',
                '--disable-features=IsolateOrigins,site-per-process',
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-accelerated-2d-canvas',
                '--disable-gpu',
                '--lang=fr-FR,fr'
            ]);

        // Fixed random scrolling behavior with proper async/await syntax
        $browsershot->evaluate("
            (async () => {
                async function randomScroll() {
                    const maxScroll = Math.max(document.documentElement.scrollHeight - window.innerHeight, 0);
                    const scrollPoints = Math.floor(Math.random() * 3) + 2;
                    const delays = Array.from({length: scrollPoints}, () => Math.floor(Math.random() * 1000) + 500);

                    for (let i = 0; i < delays.length; i++) {
                        await new Promise(resolve => {
                            setTimeout(() => {
                                window.scrollTo({
                                    top: (maxScroll / scrollPoints) * (i + 1),
                                    behavior: 'smooth'
                                });
                                resolve();
                            }, delays[i]);
                        });
                    }
                }

                await randomScroll();
                return document.documentElement.outerHTML;
            })();
        ");

        return $browsershot;
    }

    private function createPopupHandlingScript()
    {
        $selectors = json_encode($this->popupSelectors);

        return "
            async function handlePopups() {
                const selectors = $selectors;
                const maxAttempts = 5;
                let attempt = 0;

                const wait = (ms) => new Promise(resolve => setTimeout(resolve, ms));

                const simulateHumanClick = async (element) => {
                    const rect = element.getBoundingClientRect();
                    const x = rect.left + (rect.width / 2) + (Math.random() * 10 - 5);
                    const y = rect.top + (rect.height / 2) + (Math.random() * 10 - 5);

                    const mouseoverEvent = new MouseEvent('mouseover', {
                        bubbles: true,
                        cancelable: true,
                        view: window,
                        clientX: x,
                        clientY: y
                    });

                    element.dispatchEvent(mouseoverEvent);
                    await wait(Math.random() * 200 + 100);

                    const mousedownEvent = new MouseEvent('mousedown', {
                        bubbles: true,
                        cancelable: true,
                        view: window,
                        clientX: x,
                        clientY: y
                    });

                    element.dispatchEvent(mousedownEvent);
                    await wait(Math.random() * 100 + 50);

                    const clickEvent = new MouseEvent('click', {
                        bubbles: true,
                        cancelable: true,
                        view: window,
                        clientX: x,
                        clientY: y
                    });

                    element.dispatchEvent(clickEvent);
                    await wait(Math.random() * 200 + 100);
                };

                while (attempt < maxAttempts) {
                    let popupsFound = false;

                    for (const type in selectors) {
                        for (const selector of selectors[type]) {
                            const elements = document.querySelectorAll(selector);
                            if (elements.length > 0) {
                                popupsFound = true;
                                for (const element of elements) {
                                    if (element && element.offsetParent !== null &&
                                        window.getComputedStyle(element).display !== 'none' &&
                                        window.getComputedStyle(element).visibility !== 'hidden') {
                                        await simulateHumanClick(element);
                                    }
                                }
                            }
                        }
                    }

                    document.querySelectorAll('style').forEach(style => {
                        if (style.textContent.includes('overflow: hidden')) {
                            style.remove();
                        }
                    });

                    document.body.style.removeProperty('overflow');
                    document.documentElement.style.removeProperty('overflow');

                    if (!popupsFound) break;

                    attempt++;
                    await wait(Math.random() * 500 + 500);
                }

                return document.documentElement.outerHTML;
            }

            return await handlePopups();
        ";
    }

    private function checkForCaptcha($html)
    {
        $captchaStrings = [
            'captcha',
            'verify you are human',
            'security check',
            'recaptcha',
            'hcaptcha',
            'cloudflare',
            'Are you a human?',
            'Please verify',
            'Verification required'
        ];

        foreach ($captchaStrings as $string) {
            if (stripos($html, $string) !== false) {
                return true;
            }
        }

        $crawler = new Crawler($html);
        $captchaIframes = $crawler->filter('iframe[src*="captcha"], iframe[src*="recaptcha"], iframe[src*="hcaptcha"], iframe[src*="cloudflare"]')->count();
        $captchaScripts = $crawler->filter('script[src*="captcha"], script[src*="recaptcha"], script[src*="hcaptcha"], script[src*="cloudflare"]')->count();

        return $captchaIframes > 0 || $captchaScripts > 0;
    }

    private function handleRequest($url, $retryCount = 0)
    {
        $cacheKey = 'scraper_timeout_' . md5($url);

        if (Cache::has($cacheKey)) {
            $this->warn("URL is in timeout, waiting...");
            sleep(Cache::get($cacheKey));
        }

        try {
            $this->info("Requesting URL: $url");
            $this->rateLimitRequest();

            $browsershot = $this->getBrowsershot($url);
            $browsershot->setOption('logJavascriptConsole', true);

            $html = $browsershot
                ->waitUntilNetworkIdle()
                ->timeout(120000)
                ->evaluate($this->createPopupHandlingScript());

            if (empty($html)) {
                throw new \Exception("Empty HTML response received");
            }

            if ($this->checkForCaptcha($html)) {
                if ($retryCount < $this->maxRetries) {
                    $this->warn("Captcha detected, implementing backoff strategy...");
                    $backoffTime = $this->calculateBackoff($retryCount);
                    Cache::put($cacheKey, $backoffTime, now()->addMinutes(30));
                    sleep($backoffTime);
                    return $this->handleRequest($url, $retryCount + 1);
                }
                throw new \Exception("Captcha detection limit reached after {$this->maxRetries} attempts");
            }

            return $html;
        } catch (\Exception $e) {
            if ($retryCount < $this->maxRetries) {
                $this->warn("Request failed: " . $e->getMessage());
                $backoffTime = $this->calculateBackoff($retryCount);
                $this->warn("Backing off for {$backoffTime} seconds...");
                sleep($backoffTime);
                return $this->handleRequest($url, $retryCount + 1);
            }
            throw new \Exception("Request failed after {$this->maxRetries} attempts: " . $e->getMessage());
        }
    }

    private function calculateBackoff($retryCount)
    {
        return min(120, pow(2, $retryCount) + rand(1, 5));
    }

    private function rateLimitRequest()
    {
        static $lastRequest = 0;
        // Reduce minimum delay from 3-7 seconds to 1-3 seconds
        $minDelay = rand(1, 3);
        $now = microtime(true);

        if ($lastRequest > 0) {
            $elapsed = $now - $lastRequest;
            if ($elapsed < $minDelay) {
                usleep(($minDelay - $elapsed) * 1000000);
            }
        }

        $lastRequest = microtime(true);
    }

    protected function scrapeCategory($url, $site, $category)
    {
        $page = 1;
        $consecutiveEmptyPages = 0;
        $maxEmptyPages = 3;
        $sessionKey = 'scraping_session_' . md5($url . time());

        // Initialize progress bar for products
        $this->progressBar = $this->output->createProgressBar();
        $this->progressBar->setFormat(
            '%current% products found [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%'
        );
        $this->line('');
        $this->info("📊 Starting category: {$category->name}");

        do {
            $categoryPageUrl = $url . '?page=' . $page;

            try {
                if ($this->shouldRotateSession($sessionKey)) {
                    $this->rotateSession($sessionKey);
                }

                $html = $this->handleRequest($categoryPageUrl);

                if (empty($html)) {
                    $this->warn("⚠️ Empty HTML received for page $page");
                    $consecutiveEmptyPages++;
                    if ($consecutiveEmptyPages >= $maxEmptyPages) {
                        $this->warn("🛑 Max empty pages reached - ending category scrape");
                        break;
                    }
                    continue;
                }

                $crawler = new Crawler($html);
                $productsFound = $this->processProductsWithProgress($crawler, $site, $category);

                if (!$productsFound) {
                    $consecutiveEmptyPages++;
                    if ($consecutiveEmptyPages >= $maxEmptyPages) {
                        $this->warn("🛑 No products found after $maxEmptyPages pages - ending category scrape");
                        break;
                    }
                } else {
                    $consecutiveEmptyPages = 0;
                }

                $page++;
                $this->implementRandomDelay();

            } catch (\Exception $e) {
                $this->handleScrapingError($e, $category->name, $page);
                if ($this->shouldAbortScraping($e)) {
                    break;
                }
                continue;
            }
        } while (true);

        $this->progressBar->finish();
        $this->line('');
    }
    protected function processProductsWithProgress($crawler, $site, $category)
    {
        $productSelectors = [
            '.product-list-v2__container .product-card',
            '[class*="product-list"] [class*="product-card"]',
            '[class*="products"] [class*="item"]',
            '[data-product-list] [data-product-item]'
        ];

        $productsFound = false;

        foreach ($productSelectors as $selector) {
            $products = $crawler->filter($selector);
            if ($products->count() > 0) {
                $productsFound = true;
                $this->progressBar->setMaxSteps($this->progressBar->getMaxSteps() + $products->count());

                $products->each(function (Crawler $node) use ($site, $category) {
                    try {
                        $productUrl = $this->extractProductUrl($node);
                        if (!$productUrl || !$this->isValidProductUrl($productUrl)) {
                            return;
                        }

                        if (!Cache::has('product_' . md5($productUrl))) {
                            $this->scrapeProductPage($productUrl, $site, $category);
                            Cache::put('product_' . md5($productUrl), true, now()->addHours(24));
                            $this->totalProductsFound++;
                            $this->progressBar->advance();
                        }
                    } catch (\Exception $e) {
                        Log::error("Error processing product", [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                    }
                });
                break;
            }
        }

        return $productsFound;
    }


    private function processProducts($products, $site, $category)
    {
        $products->each(function (Crawler $node) use ($site, $category) {
            try {
                $productUrl = $this->extractProductUrl($node);
                if (!$productUrl) {
                    return;
                }

                if (!$this->isValidProductUrl($productUrl)) {
                    $this->warn("Invalid product URL: $productUrl");
                    return;
                }

                if (!Cache::has('product_' . md5($productUrl))) {
                    $this->scrapeProductPage($productUrl, $site, $category);
                    Cache::put('product_' . md5($productUrl), true, now()->addHours(24));
                }
            } catch (\Exception $e) {
                Log::error("Error processing product", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        });
    }

    private function extractProductUrl(Crawler $node)
    {
        $urlSelectors = [
            '.S-product-card__img-container',
            'a[href*="product"]',
            '[class*="product"] a',
            'a[data-product-link]'
        ];

        foreach ($urlSelectors as $selector) {
            try {
                $url = $node->filter($selector)->attr('href');
                if ($url) {
                    return $this->normalizeUrl($url);
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return null;
    }

    private function normalizeUrl($url)
    {
        if (strpos($url, 'http') !== 0) {
            $url = 'https://fr.shein.com' . ($url[0] === '/' ? '' : '/') . $url;
        }
        return $url;
    }

    private function scrapeProductPage($productUrl, $site, $category)
    {
        $retryCount = 0;
        $maxRetries = 3;

        while ($retryCount < $maxRetries) {
            try {
                $this->info("Scraping product: $productUrl (Attempt " . ($retryCount + 1) . ")");

                $html = $this->handleRequest($productUrl);
                $crawler = new Crawler($html);

                $productData = $this->extractProductData($crawler);

                if ($this->isValidProductData($productData)) {
                    $this->saveProduct($productData, $productUrl, $site, $category);
                    return;
                }

                $retryCount++;
                $this->implementRandomDelay();

            } catch (\Exception $e) {
                Log::error("Product scraping error", [
                    'url' => $productUrl,
                    'error' => $e->getMessage(),
                    'attempt' => $retryCount + 1
                ]);

                if ($this->shouldAbortScraping($e)) {
                    throw $e;
                }

                $retryCount++;
                $this->implementRandomDelay();
            }
        }
    }

    private function extractProductData(Crawler $crawler)
    {
        $data = [];

        foreach ($this->productSelectors as $field => $selectors) {
            foreach ($selectors as $selector) {
                try {
                    if ($field === 'image') {
                        $data[$field] = $crawler->filter($selector)->attr('src');
                    } else {
                        $data[$field] = trim($crawler->filter($selector)->text());
                    }

                    if (!empty($data[$field])) {
                        break;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        return $data;
    }

    private function isValidProductData($data)
    {
        return !empty($data['name']) &&
               isset($data['price']) &&
               $this->cleanPrice($data['price']) > 0;
    }

    private function saveProduct($data, $url, $site, $category)
    {
        try {
            $product = Product::updateOrCreate(
                ['source_url' => $url],
                [
                    'name' => $data['name'],
                    'price' => $this->cleanPrice($data['price']),
                    'description' => $data['description'] ?? '',
                    'site_id' => $site->id,
                    'category_id' => $category->id,
                ]
            );

            $this->info("Saved product: {$data['name']}");

        } catch (\Exception $e) {
            Log::error("Error saving product", [
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function implementRandomDelay()
    {
        $delay = rand(1000, 3000) / 1000;
        $this->info("Waiting for $delay seconds...");
        usleep($delay * 1000000);
    }

    private function shouldRotateSession($sessionKey)
    {
        return Cache::get($sessionKey, 0) >= $this->sessionDuration;
    }

    private function rotateSession($sessionKey)
    {
        $this->info("Rotating session...");
        Cache::put($sessionKey, 0, now()->addHours(1));
        sleep(rand(5, 10));
    }

    private function isValidProductUrl($url)
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false &&
               strpos($url, 'shein.com') !== false;
    }

    private function shouldAbortScraping(\Exception $e)
    {
        $criticalErrors = [
            'Captcha detection limit reached',
            'Access denied',
            'IP blocked',
            'Too many requests'
        ];

        foreach ($criticalErrors as $error) {
            if (stripos($e->getMessage(), $error) !== false) {
                return true;
            }
        }

        return false;
    }

    private function handleScrapingError(\Exception $e, $categoryName, $page)
    {
        $this->error("Error scraping category $categoryName page $page: " . $e->getMessage());
        Log::error("Scraping error", [
            'category' => $categoryName,
            'page' => $page,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        // Reduce error backoff from 30-60 to 15-30 seconds
        $backoffTime = rand(15, 30);
        $this->warn("Backing off for $backoffTime seconds...");
        sleep($backoffTime);
    }

    private function cleanPrice($price)
    {
        $price = preg_replace('/[^0-9,.]/', '', str_replace(',', '.', $price));
        return (float) $price ?: 0.0;
    }
}
