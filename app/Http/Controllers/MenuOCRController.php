<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use thiagoalessio\TesseractOCR\TesseractOCR;
use App\Models\Menu;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use OpenAI;
use App\Models\UserPreference;
use App\Models\DishView;
use Illuminate\Support\Facades\Auth;

class MenuOCRController extends Controller
{
    private $openai;

    public function __construct()
    {
        $this->openai = OpenAI::client(config('services.openai.api_key'));
    }

    public function processMenu(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'menu_file' => 'required|file|mimes:jpg,png,pdf|max:10240'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed',
                    'details' => $validator->errors()
                ], 422);
            }

            Log::info('Processing menu file', ['filename' => $request->file('menu_file')->getClientOriginalName()]);

            $file = $request->file('menu_file');
            $path = Storage::disk('local')->putFile('temp', $file);
            $fullPath = Storage::disk('local')->path($path);
            
            // Convert PDF to image if needed
            if ($file->getClientOriginalExtension() === 'pdf') {
                $fullPath = $this->convertPdfToImage($fullPath);
            }

            // Perform OCR
            $text = $this->performOCR($fullPath);
            
            // Phase 1: Extraction of Dishes and Prices
            $extractedItems = $this->extractDishesAndPrices($text);
            
            // Phase 2: Generation of Descriptions and Categories
            $menuItems = $this->generateDescriptionsAndCategories($extractedItems);
            
            // Get user preferences if authenticated
            $userPreferences = $this->getUserPreferences(Auth::id());

            // Get personalized recommendations
            $recommendedItems = $this->getRecommendedItems($menuItems, $userPreferences);

            // Save to database
            $savedItems = $this->saveMenuItems($menuItems);

            return response()->json([
                'message' => 'Menu processed successfully',
                'items_count' => count($savedItems),
                'items' => $recommendedItems,
                'raw_text' => app()->environment('local') ? $text : null,
                'personalized' => !is_null($userPreferences),
            ]);

        } catch (\Exception $e) {
            Log::error('Menu processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => $e->getMessage(),
                'details' => app()->environment('local') ? $e->getTraceAsString() : null
            ], 500);
        }
    }
    public function showMenu()
    {
        // Obtener todos los elementos del menú desde la base de datos
        $menuItems = Menu::all()->toArray();

        // Obtener las preferencias del usuario autenticado
        $userPreferences = $this->getUserPreferences(Auth::id());

        // Obtener recomendaciones personalizadas
        $menuItems = $this->getRecommendedItems($menuItems, $userPreferences);

        // Pasar las variables a la vista
        return view('menu', compact('menuItems', 'userPreferences'));
    }

    private function convertPdfToImage($pdfPath)
    {
        Log::info('Converting PDF to image');
        $outputPath = Storage::disk('local')->path('temp/output');
        exec("pdftoppm -png {$pdfPath} {$outputPath}", $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new \Exception('PDF conversion failed');
        }
        
        return $outputPath . '-1.png';
    }

    private function performOCR($imagePath)
    {
        Log::info('Running OCR on file', ['path' => $imagePath]);
        
        $text = (new TesseractOCR($imagePath))
            ->lang('spa')
            ->run();

        if (empty($text)) {
            throw new \Exception('OCR resulted in empty text');
        }

        return $this->cleanText($text);
    }

    private function cleanText($text)
    {
        // 1. Basic cleaning
        $text = preg_replace('/[\x00-\x1F\x7F-\x9F\x{2500}-\x{257F}]/u', '', $text);
        
        // 2. Replace special characters with spaces
        $text = str_replace(['_', '/', '\\'], ' ', $text);
        
        // 3. Normalize spaces and line breaks
        $text = preg_replace('/\s+/', ' ', $text);
        $text = preg_replace('/\s*([-,:;.])\s*/', '$1', $text);
        
        // 4. Format prices, handle ranges and clean prices
        $text = preg_replace('/(\d+)([.,]\d{1,2})?\s*[-\/]\s*(\d+)([.,]\d{1,2})?\s*(?:[€$]|[\p{Sc}])?/u', '$1$2-$3$4', $text);
        $text = preg_replace('/(\d+)([.,]\d{1,2})?\s*(?:[€$]|[\p{Sc}])?/u', '$1$2', $text);
        
        // 5. Separate items with newlines when price is followed by text
        $text = preg_replace('/(\d+[\.,]?\d{0,2}(?:-\d+[\.,]?\d{0,2})?)\s+([^\d]+)/u', "$1\n$2", $text);
        
        // 6. Remove non-numeric characters from prices within the text
        $text = preg_replace('/(\d+[\.,]?\d{0,2}(?:-\d+[\.,]?\d{0,2})?)[^\S\n]*(?=[A-Za-z])/u', "$1\n", $text);
        
        // 7. Remove common non-menu words
        $commonWords = ['unidad', 'informacion', 'alergenos', 'precios expresados', 'iva incluido', 'guarnicion'];
        $text = preg_replace('/\b(' . implode('|', $commonWords) . ')\b/iu', '', $text);
        
        // 8. Clean up final text
        $text = preg_replace('/[^a-zA-Z0-9áéíóúÁÉÍÓÚñÑ\s\*\-\.,\n]/u', '', $text);
        
        return trim($text);
    }

    private function buildOpenAIPrompt($text, $attempt = 0, $category = null) {
        $variation = match ($attempt) {
            0 => "",
            1 => "\n(Segundo intento: Asegúrate de identificar cada plato individual con su precio)",
            2 => "\n(Último intento: Extrae SOLO platos que tengan un precio claro)",
            default => ""
        };

        $categoryExample = $category ? ", \"category\": \"{$category}\"" : "";

        return <<<EOT
        Analiza este menú y extrae cada plato individual como un objeto JSON. IMPORTANTE:

        1. Cada plato DEBE tener nombre y precio
        2. El precio es el indicador principal de un plato nuevo
        3. Si ves un precio, debe corresponder a UN SOLO plato
        4. NO agrupes platos ni uses precios como rangos
        5. Revisa si la descripcion es coherente con el platillo.
        
        Ejemplos correctos:
        [
          {"name": "Hamburguesa Clásica", "price": "10.50", "description": "Jugosa hamburguesa con queso"{$categoryExample}},
          {"name": "Ensalada César", "price": "8.00"}
        ]

        Menú a procesar:{$variation}
        {$text}
        EOT;
    }

    private function extractMenuItems($text)
    {
        $maxRetries = 3;
        $attempt = 0;
        $lastError = null;

        while ($attempt < $maxRetries) {
            try {
                // Modify text slightly on retries
                $processedText = $this->processTextForAttempt($text, $attempt);
                $prompt = $this->buildOpenAIPrompt($processedText, $attempt);
                
                Log::debug('Attempt ' . ($attempt + 1) . ' text:', ['text' => $processedText]);

                $response = $this->openai->chat()->create([
                    'model' => 'gpt-3.5-turbo',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Eres un parser de menús. Devuelve SOLO JSON válido.'
                        ],
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'temperature' => $attempt > 0 ? 0.5 : 0.3,
                    'max_tokens' => 2000
                ]);

                $content = $response->choices[0]->message->content;
                Log::debug('Raw OpenAI response:', ['content' => $content]);

                // Clean and validate JSON
                $content = $this->cleanAndValidateJSON($content);
                Log::debug('Cleaned JSON:', ['content' => $content]);

                $menuItems = json_decode($content, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('JSON decode error: ' . json_last_error_msg());
                }

                // Validate menu items
                return $this->validateMenuItems($menuItems);

            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                Log::error('Attempt ' . ($attempt + 1) . ' failed:', [
                    'error' => $lastError,
                    'response' => $content ?? null
                ]);

                $attempt++;
                if ($attempt >= $maxRetries) {
                    // Try fallback parser as last resort
                    return $this->fallbackParser($text);
                }

                sleep(1);
            }
        }
    }

    private function processTextForAttempt($text, $attempt)
    {
        return match ($attempt) {
            0 => $text,
            1 => $this->addLineBreaksAfterPrices($text),
            2 => $this->simplifyText($text),
            default => $text
        };
    }

    private function addLineBreaksAfterPrices($text)
    {
        return preg_replace('/(\d+\.\d{2})\s+/', "$1\n", $text);
    }

    private function simplifyText($text)
    {
        // Keep only lines with clear price patterns
        $lines = explode("\n", $text);
        $filtered = array_filter($lines, function($line) {
            return preg_match('/\d+\.\d{2}/', $line);
        });
        return implode("\n", $filtered);
    }

    private function fallbackParser($text)
    {
        // Simple regex-based parser as last resort
        $items = [];
        $lines = explode("\n", $text);
        
        foreach ($lines as $line) {
            if (preg_match('/(.+?)\s*(\d+\.\d{2})/', $line, $matches)) {
                $items[] = [
                    'name' => trim($matches[1]),
                    'price' => $matches[2],
                    'description' => 'Sin descripción',
                    'category' => 'Sin Categoría'
                ];
            }
        }
        
        return $items;
    }

    private $keywords = [
        'Entradas' => ['entrante', 'aperitivo', 'para picar', 'tapa'],
        'Ensaladas' => ['ensalada', 'mezclum', 'lechuga', 'tomate'],
        'Carnes' => ['carne', 'pollo', 'ternera', 'cerdo', 'pescado', 'bistec', 'filete'],
        'Postres' => ['postre', 'dulce', 'pastel', 'tarta', 'helado'],
        // ...más categorías
    ];

    private function assignCategory(string $dishName, string $dishDescription, array $keywords): ?string
    {
        foreach ($keywords as $category => $categoryKeywords) {
            foreach ($categoryKeywords as $keyword) {
                if (stripos($dishName, $keyword) !== false || stripos($dishDescription, $keyword) !== false) {
                    return $category;
                }
            }
        }
        return null; // No se encontró una categoría
    }

    private function saveMenuItems($menuItems)
    {
        $savedItems = [];
        
        foreach ($menuItems as $item) {
            if (isset($item['name']) && !empty(trim($item['name']))) {
                // Analyze dish for allergens
                $allergens = $this->analyzeDishForAllergens($item['name'], $item['description'] ?? '');

                $savedItems[] = Menu::create([
                    'dish_name' => $item['name'],
                    'price' => $item['price'],
                    'description' => $item['description'] ?? null,
                    'category' => $item['category'] ?? 'Sin Categoría',
                    'allergens' => $allergens // Store allergens directly
                ]);
            } else {
                Log::warning('Platillo omitido, sin nombre:', ['dish' => $item]);
            }
        }

        return array_map(function($item) {
            $data = $item->toArray();
            // Cast allergens to array if it's a string (for compatibility with existing data)
            $data['allergens'] = is_string($data['allergens']) ? json_decode($data['allergens']) : $data['allergens'];
            return $data;
        }, $savedItems);
    }

    private function extractDishesAndPrices($text)
    {
        $maxRetries = 3;
        $attempt = 0;
        $lastError = null;

        while ($attempt < $maxRetries) {
            try {
                $prompt = $this->buildExtractionPrompt($text, $attempt);
                Log::debug('Sending extraction prompt to OpenAI:', ['attempt' => $attempt + 1, 'prompt' => $prompt]);

                $response = $this->openai->chat()->create([
                    'model' => 'gpt-3.5-turbo',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Eres un parser de menús. Devuelve SOLO JSON válido.'
                        ],
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'temperature' => $attempt > 0 ? 0.5 : 0.3,
                    'max_tokens' => 2000
                ]);

                $content = $response->choices[0]->message->content;
                Log::debug('Raw OpenAI extraction response:', ['content' => $content]);

                // Clean and validate JSON
                $content = $this->cleanAndValidateJSON($content);
                Log::debug('Cleaned JSON:', ['content' => $content]);

                $extractedItems = json_decode($content, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('JSON decode error: ' . json_last_error_msg());
                }

                // Validate extracted items
                return $this->validateExtractedItems($extractedItems);

            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                Log::error('Extraction attempt ' . ($attempt + 1) . ' failed:', [
                    'error' => $lastError,
                    'response' => $content ?? null
                ]);

                $attempt++;
                if ($attempt >= $maxRetries) {
                    Log::warning('Usando fallback parser para extracción de platos y precios.');
                    $fallbackItems = $this->fallbackParser($text);
                    return array_map(function ($item) {
                        return ['name' => $item['name'], 'price' => $item['price']];
                    }, $fallbackItems);
                }

                sleep(1);
            }
        }
    }

    private function buildExtractionPrompt($text, $attempt = 0)
    {
        $additionalInstructions = match ($attempt) {
            1 => "\nEn el segundo intento, asegúrate de seguir las instrucciones exactamente.",
            2 => "\nÚltimo intento: Por favor, sigue las instrucciones al pie de la letra.",
            default => ""
        };
        
        return <<<EOT
Extrae únicamente los nombres de los platos y sus precios del siguiente menú. Devuelve un array JSON con la siguiente estructura:

[
  {"name": "Nombre del plato", "price": "Precio"},
  {"name": "Otro plato", "price": "Precio"}
]

Instrucciones importantes:
* El precio es el indicador principal de un nuevo plato.
* Un plato **siempre** tiene un precio numérico o un rango numérico, sin caracteres adicionales.
* Los precios deben ser números enteros o decimales (por ejemplo, 10, 10.50) o rangos (por ejemplo, 6-12), sin símbolos de moneda.
* No incluyas platos sin precio. Si no puedes determinar el precio de un plato, **ómítelo**.
* No incluyas descripciones ni categorías en esta fase.
* Precios incorrectos como "e" o combinaciones no numéricas deben ser ignorados.

Ejemplos de precios válidos:
- "10"
- "10.50"
- "6-12"
- "8.95-17.50"

Menú a procesar:
{$text}
{$additionalInstructions}
EOT;
    }

    private function validateExtractedItems($items)
    {
        if (!is_array($items) || empty($items)) {
            throw new \Exception('No dish items found in extraction response');
        }

        foreach ($items as $item) {
            if (!isset($item['name']) || !isset($item['price']) || empty(trim($item['price']))) {
                throw new \Exception('Missing or empty required fields in extraction');
            }
            // Ensure price is a valid number or range
            if (!preg_match('/^\d+(\.\d{1,2})?(-\d+(\.\d{1,2})?)?$/', $item['price'])) {
                throw new \Exception('Invalid price format for item: ' . $item['name']);
            }
        }

        return $items;
    }
    public function showPreferences()
    {
        // Obtener las preferencias del usuario
        $userPreferences = $this->getUserPreferences(Auth::id());
    
        // Pasar las preferencias a la vista
        return view('preferencias', ['userPreferences' => $userPreferences]);
    }

    private function generateDescriptionsAndCategories($extractedItems) {
        $maxRetries = 3;
        $attempt = 0;
        $lastError = null;

        while ($attempt < $maxRetries) {
            try {
                $prompt = $this->buildDescriptionPrompt($extractedItems, $attempt);
                Log::debug('Sending description prompt to OpenAI (attempt ' . ($attempt + 1) . '):', ['prompt' => $prompt]);

                $response = $this->openai->chat()->create([
                    'model' => 'gpt-3.5-turbo',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Eres un asistente que mejora información de menús. Devuelve SOLO JSON válido.'
                        ],
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'temperature' => 0.5,
                    'max_tokens' => 3000
                ]);

                $content = $response->choices[0]->message->content;
                Log::debug('Raw OpenAI description response:', ['content' => $content]);

                // Clean and validate JSON
                $content = $this->cleanAndValidateJSON($content);
                Log::debug('Cleaned JSON:', ['content' => $content]);

                $menuItems = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

                return $this->validateMenuItems($menuItems, true);

            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                Log::error('Description generation attempt ' . ($attempt + 1) . ' failed:', [
                    'error' => $lastError,
                    'response' => $content ?? null
                ]);

                $attempt++;
                if ($attempt >= $maxRetries) {
                    throw new \Exception('Failed to generate descriptions after multiple attempts: ' . $lastError);
                }

                sleep(1);
            }
        }
    }

    private function buildDescriptionPrompt($extractedItems, $attempt = 0) {
        $itemsJson = json_encode($extractedItems, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $additionalInstructions = match ($attempt) {
            1 => "\nAsegúrate de que la respuesta siga el formato especificado, incluyendo los campos \"description\", \"category\" y \"allergens\".",
            2 => "\nÚltimo intento. Si no puedes generar la descripción o categoría, déjalas en blanco como \"Sin descripción\"/\"Sin categoría\", pero mantén la estructura JSON.",
            default => ""
        };

        return <<<EOT
        Genera descripciones concisas (máximo 50 caracteres) y categorías para los siguientes platos. Devuelve SOLO el array JSON con los campos "name", "price", "description", "category" y "allergens" (como array) para cada plato.

        Ejemplos de alérgenos:
        * "Tartar de salmón": ["Pescado"]
        * "Ensalada con queso de cabra": ["Lácteos"]
        * "Pasta con frutos secos": ["Gluten", "Frutos Secos"]
        * "Pollo al ajillo": []

        Si no puedes determinar los alérgenos, devuelve un array vacío.

        Platos a procesar:
        {$itemsJson}
        {$additionalInstructions}
        EOT;
    }

    private function validateMenuItems($items, $requireDescriptionAndCategory = false)
    {
        if (!is_array($items) || empty($items)) {
            throw new \Exception('No menu items found in response');
        }

        return array_map(function($item) use ($requireDescriptionAndCategory) {
            if (!isset($item['name']) || !isset($item['price']) || empty(trim($item['price']))) {
                throw new \Exception('Missing or empty required fields: name or price');
            }

            // Validate price format
            if (!preg_match('/^\d+(\.\d{1,2})?(-\d+(\.\d{1,2})?)?$/', $item['price'])) {
                throw new \Exception('Invalid price format for item: ' . $item['name']);
            }

            if ($requireDescriptionAndCategory) {
                if (!isset($item['description']) || !isset($item['category']) || empty(trim($item['description'])) || empty(trim($item['category']))) {
                    throw new \Exception('Missing or empty required fields: description or category');
                }
                if (!isset($item['allergens']) || !is_array($item['allergens'])) {
                    throw new \Exception('Faltan los alérgenos en el platillo: ' . $item['name']);
                }
            }

            return [
                'name' => trim($item['name']),
                'price' => preg_replace('/[^0-9\.\-]/', '', $item['price']),
                'description' => trim($item['description'] ?? 'Sin descripción'),
                'category' => trim($item['category'] ?? $this->assignCategory($item['name'], $item['description'], $this->keywords)),
                'allergens' => $item['allergens']
            ];
        }, $items);
    }

    private function cleanAndValidateJSON($content) {
        Log::debug('Raw content before any cleaning:', ['content' => $content]);
        
        // Remove control characters and non-printable characters
        $content = preg_replace('/[\x00-\x1F\x7F\xA0]/u', '', $content);
        
        // Extract JSON array pattern
        if (!preg_match('/\[\s*\{.*\}\s*\]/s', $content, $matches)) {
            // Try more aggressive extraction if first attempt fails
            if (!preg_match('/\{.*\}/s', $content, $matches)) {
                throw new \Exception('No se encontró ninguna estructura JSON válida (ni array ni objeto): ' . 
                    substr($content, 0, 200) . '...');
            }
            Log::debug('Found single object, wrapping in array');
            $content = '[' . $matches[0] . ']';
        } else {
            $content = $matches[0];
        }
        
        // Remove any remaining whitespace outside of strings
        $content = preg_replace('/\s+(?=([^"]*"[^"]*")*[^"]*$)/', '', $content);
        
        // Verify structure
        if (!$this->checkBracketBalance($content)) {
            throw new \Exception('JSON tiene llaves o corchetes desbalanceados: ' . $content);
        }

        return $content;
    }

    private function checkBracketBalance($json): bool 
    {
        $stack = [];
        $pairs = [
            '{' => '}',
            '[' => ']'
        ];
        
        for ($i = 0; $i < strlen($json); $i++) {
            $char = $json[$i];
            if (in_array($char, ['{', '['])) {
                $stack[] = $char;
            } elseif (in_array($char, ['}', ']'])) {
                if (empty($stack)) {
                    return false;
                }
                $last = array_pop($stack);
                if ($pairs[$last] !== $char) {
                    return false;
                }
            }
        }
        
        return empty($stack);
    }

    private function fixCommonJsonErrors($json)
    {
        // Fix unescaped quotes within JSON strings
        $json = preg_replace('/([^\\])\\\"/', '$1\\\\"', $json);
        return $json;
    }

    private function analyzeDishForAllergens($name, $description) 
    {
        $allergens = [];
        
        // Expanded allergen map with more specific terms
        $allergenMap = [
            'gluten' => [
                'pan', 'harina', 'trigo', 'pasta', 'cebada', 'centeno', 
                'avena', 'espelta', 'kamut', 'triticale', 'sémola', 'cuscús'
            ],
            'pescado' => [
                'pescado', 'merluza', 'bacalao', 'salmón', 'dorada', 'atún',
                'bonito', 'lubina', 'rape', 'sardina', 'boquerón'
            ],
            'crustáceos' => [
                'gamba', 'langostino', 'carabinero', 'cigala', 'bogavante',
                'cangrejo', 'nécora', 'percebe'
            ],
            'moluscos' => [
                'pulpo', 'calamar', 'sepia', 'mejillón', 'almeja', 'berberecho',
                'vieira', 'caracol'
            ],
            'lácteos' => [
                'queso', 'leche', 'lácteo', 'mantequilla', 'nata', 'yogur',
                'crema', 'caseína', 'requesón'
            ],
            'huevos' => ['huevo', 'tortilla', 'clara', 'yema', 'mayonesa'],
            'frutos_secos' => [
                'almendra', 'nuez', 'piñón', 'anacardo', 'avellana', 
                'pistacho', 'cacahuete'
            ],
            'soja' => ['soja', 'salsa de soja', 'edamame', 'tofu'],
            'mostaza' => ['mostaza'],
            'apio' => ['apio'],
            'sésamo' => ['sésamo', 'ajonjolí', 'tahini']
        ];
    
        // Negation words in Spanish
        $negationWords = ['sin', 'no contiene', 'libre de'];
        
        $combinedText = mb_strtolower($name . ' ' . $description);
    
        foreach ($allergenMap as $allergen => $keywords) {
            foreach ($keywords as $keyword) {
                // Check for word boundaries using regex
                if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/u', $combinedText)) {
                    // Check if keyword is negated
                    $isNegated = false;
                    foreach ($negationWords as $negation) {
                        if (mb_strpos($combinedText, $negation . ' ' . $keyword) !== false) {
                            $isNegated = true;
                            break;
                        }
                    }
                    
                    if (!$isNegated) {
                        $allergens[] = $allergen;
                        break; // Skip remaining keywords for this allergen
                    }
                }
            }
        }
    
        return array_map('strtolower', array_unique($allergens));
    }

    private function getUserPreferences($userId = null)
    {
        if (!$userId) {
            return null;
        }

        $preferences = UserPreference::where('user_id', $userId)->first();

        if ($preferences) {
            return [
                'dietary_restrictions' => $preferences->dietary_restrictions ?? [],
                'favorite_tags' => $preferences->favorite_tags ?? [],
                'order_history' => $preferences->order_history ?? [],
            ];
        }

        return null;
    }

    private function getRecommendedItems(array $menuItems, ?array $userPreferences = null)
    {
        if (!$userPreferences) {
            return $menuItems;
        }
    
        $restrictions = $userPreferences['dietary_restrictions'] ?? [];
        $favoriteTags = $userPreferences['favorite_tags'] ?? [];
        $previousOrders = $userPreferences['order_history'] ?? [];
    
        // Mapa de equivalencias para alergenos
        $allergenEquivalence = [
            'gluten' => ['gluten'],
            'lácteos' => ['lácteos'],
            'frutos secos' => ['frutos_secos'],
            'pescado' => ['pescado'],
            'mariscos' => ['pescado', 'crustáceos', 'moluscos'],
            'huevos' => ['huevos']
        ];
    
        // Mapa de equivalencias para categorías
        $categoryMap = [
            'entrante' => 'entradas',
            'entrantes' => 'entradas',
            'principal' => 'carnes',
            'carne' => 'carnes',
            'carnes' => 'carnes',
            'pescado' => 'pescados',
            'pescados' => 'pescados',
            'ensalada' => 'ensaladas',
            'ensaladas' => 'ensaladas',
            'postre' => 'postres',
            'postres' => 'postres',
            'platos principales' => 'carnes'
        ];
    
        $items = collect($menuItems)->map(function($item) use ($allergenEquivalence, $restrictions, $favoriteTags, $previousOrders, $categoryMap) {
            // Normalizar alérgenos
            $itemAllergens = array_map('strtolower', $item['allergens'] ?? []);
            
            // Verificar alérgenos restringidos
            $restrictedAllergens = collect($restrictions)->flatMap(function($restriction) use ($allergenEquivalence) {
                return $allergenEquivalence[strtolower($restriction)] ?? [$restriction];
            })->toArray();
            $item['has_allergens'] = !empty(array_intersect($restrictedAllergens, $itemAllergens));
    
            // Normalizar categoría
            $itemCategory = strtolower(trim($item['category'] ?? ''));
            $normalizedCategory = $categoryMap[$itemCategory] ?? $itemCategory;
            
            // Normalizar categorías favoritas
            $normalizedFavoriteTags = collect($favoriteTags)->map(function($tag) {
                return strtolower(trim($tag));
            })->toArray();
    
            $item['is_favorite'] = in_array($normalizedCategory, $normalizedFavoriteTags);
            $item['normalized_category'] = $normalizedCategory;
    
            // Calcular puntuación
            $score = 0;
            if ($item['is_favorite']) {
                $score += 5;
            }
            if (in_array($item['dish_name'], $previousOrders)) {
                $score += 2;
            }
            $item['recommendation_score'] = $score;
    
            return $item;
        });
    
        // Ordenar: favoritos primero, luego por puntuación
        return $items->sortByDesc(function($item) {
            return [$item['is_favorite'], $item['recommendation_score']];
        })->values()->all();
    }

public function rateDish(Request $request, $dishId)
{
    $validated = $request->validate([
        'rating' => 'required|integer|between:1,5'
    ]);

    $rating = Rating::updateOrCreate(
        [
            'user_id' => Auth::id(),
            'dish_id' => $dishId
        ],
        ['rating' => $validated['rating']]
    );

    // Update user preferences with this interaction
    $dish = Menu::findOrFail($dishId);
    $userPref = UserPreference::firstOrCreate(['user_id' => Auth::id()]);
    $userPref->addToOrderHistory($dish->dish_name);

    return response()->json([
        'message' => 'Rating saved successfully',
        'new_recommendations' => $this->getRecommendedItems(Menu::all()->toArray(), $this->getUserPreferences(Auth::id()))
    ]);
}

public function updatePreferences(Request $request)
{
    $validated = $request->validate([
        'dietary_restrictions' => 'nullable|array',
        'favorite_tags' => 'nullable|array',
        'dietary_restrictions.*' => 'string',
        'favorite_tags.*' => 'string'
    ]);

    // Actualizar o crear preferencias del usuario
    $preferences = UserPreference::updateOrCreate(
        ['user_id' => Auth::id()],
        [
            'dietary_restrictions' => $validated['dietary_restrictions'] ?? [],
            'favorite_tags' => $validated['favorite_tags'] ?? [],
        ]
    );

    return redirect()->route('preferencias')
        ->with('status', 'preferences-updated');
}

    public function filterMenuItems(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'min_price' => 'nullable|numeric|min:0',
            'max_price' => 'nullable|numeric|min:0',
            'category' => 'nullable|string',
            'restrictions' => 'nullable|array',
            'favorite_tags' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $minPrice = $request->input('min_price', 0);
        $maxPrice = $request->input('max_price', 999999);
        $category = $request->input('category');
        $restrictions = $request->input('restrictions', []);
        
        try {
            $query = Menu::query();

            // Apply price filter
            $query->where(function($q) use ($minPrice, $maxPrice) {
                $q->where(function($sub) use ($minPrice, $maxPrice) {
                    // Handle single prices
                    $sub->whereRaw('CAST(REGEXP_REPLACE(price, "[^0-9.]", "") AS DECIMAL(10,2)) >= ?', [$minPrice])
                        ->whereRaw('CAST(REGEXP_REPLACE(price, "[^0-9.]", "") AS DECIMAL(10,2)) <= ?', [$maxPrice]);
                });
            });

            if ($category) {
                $query->where('category', $category);
            }

            if (!empty($restrictions)) {
                $query->where(function($q) use ($restrictions) {
                    foreach ($restrictions as $restriction) {
                        $q->whereJsonContains('allergens', $restriction);
                    }
                });
            }

            $menuItems = $query->get();

            // Update user preferences
            $preference = UserPreference::updateOrCreate(
                ['user_id' => Auth::id()],
                [
                    'dietary_restrictions' => $request->input('restrictions', []),
                    'favorite_tags' => $request->input('favorite_tags', [])
                ]
            );

            return response()->json([
                'filtered_items' => $menuItems,
                'preferences_updated' => true
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An error occurred while filtering menu items'], 500);
        }
    }

    public function getMenuItems(Request $request)
    {
        $items = Menu::query();
        
        if ($request->has('category')) {
            $items->where('category', $request->category);
        }
        
        if ($request->has('price_range')) {
            $range = explode('-', $request->price_range);
            $items->whereBetween('price', $range);
        }
        
        $menuItems = $items->get();
        
        // Get user preferences if authenticated
        $userPreferences = $this->getUserPreferences(Auth::id());
        
        // Apply recommendations
        $recommendedItems = $this->getRecommendedItems($menuItems->toArray(), $userPreferences);
        
        return response()->json([
            'items' => $recommendedItems,
            'preferences_applied' => !is_null($userPreferences)
        ]);
    }

    public function trackDishView(Request $request)
    {
        $validated = $request->validate([
            'dish_id' => 'required|exists:menus,id',
        ]);

        DishView::recordView($validated['dish_id'], Auth::id());

        return response()->json(['message' => 'View tracked successfully']);
    }

    public function getPopularDishes(Request $request)
    {
        $timeframe = $request->input('timeframe', 24); // hours
        $limit = $request->input('limit', 5);

        $popularDishes = DishView::getTrendingDishes($timeframe, $limit);

        return response()->json([
            'popular_dishes' => $popularDishes,
            'timeframe' => $timeframe
        ]);
    }

    public function getUserPreferencesApi()
    {
        $preferences = UserPreference::where('user_id', Auth::id())->first();
        
        return response()->json([
            'preferences' => $preferences ?? [],
            'common_restrictions' => UserPreference::getCommonRestrictions(),
            'popular_categories' => UserPreference::getPopularCategories()
        ]);
    }
}






