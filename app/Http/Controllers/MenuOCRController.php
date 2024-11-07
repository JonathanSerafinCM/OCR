<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use thiagoalessio\TesseractOCR\TesseractOCR;
use App\Models\Menu;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use OpenAI;

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
            
            // Save to database
            $savedItems = $this->saveMenuItems($menuItems);

            return response()->json([
                'message' => 'Menu processed successfully',
                'items_count' => count($savedItems),
                'items' => $savedItems,
                'raw_text' => app()->environment('local') ? $text : null
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

    private function buildOpenAIPrompt($text, $attempt = 0)
    {
        $variation = match ($attempt) {
            0 => "",
            1 => "\n(Segundo intento: Asegúrate de identificar cada plato individual con su precio)",
            2 => "\n(Último intento: Extrae SOLO platos que tengan un precio claro)",
            default => ""
        };

        return <<<EOT
        Analiza este menú y extrae cada plato individual como un objeto JSON. IMPORTANTE:

        1. Cada plato DEBE tener nombre y precio
        2. El precio es el indicador principal de un plato nuevo
        3. Si ves un precio, debe corresponder a UN SOLO plato
        4. NO agrupes platos ni uses precios como rangos
        5. Revisa si la descripcion es coherente con el platillo.
        
        Ejemplos correctos:
        [
          {"name": "Hamburguesa Clásica", "price": "10.50", "description": "Jugosa hamburguesa con queso", "category": "Hamburguesas"},
          {"name": "Ensalada César", "price": "8.00", "description": "Lechuga, pollo y aderezo", "category": "Ensaladas"}
        ]

        Ejemplos INCORRECTOS:
        ❌ {"name": "Ensalada mixta con guarnición", "price": "8.00, 10.00"} // NO múltiples precios

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
            $category = $item['category'] ?? null;
            
            if (!$category) {
                $assignedCategory = $this->assignCategory($item['name'], $item['description'], $this->keywords);
                if ($assignedCategory) {
                    $category = $assignedCategory;
                } else {
                    $category = 'Sin Categoría';
                }
            }

            // Verifica si dish_name existe ANTES de intentar insertar
            if (isset($item['name']) && !empty(trim($item['name']))) { 
                $savedItems[] = Menu::create([
                    'dish_name' => $item['name'], // Changed from 'name' to 'dish_name'

                    'price' => $item['price'],
                    'description' => $item['description'] ?? null,
                    'category' => $category
                ]);
            } else {
                // Maneja la ausencia de dish_name
                Log::warning('Platillo omitido, sin nombre:', ['dish' => $item]);
            }
        }

        return $savedItems;
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

    private function generateDescriptionsAndCategories($extractedItems)
    {
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

    private function buildDescriptionPrompt($extractedItems, $attempt = 0)
    {
        $itemsJson = json_encode($extractedItems, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $additionalInstructions = match ($attempt) {
            1 => "\nAsegúrate de agregar descripciones y categorías adecuadas a cada plato.",
            2 => "\nÚltimo intento: Por favor, sigue exactamente el formato y las instrucciones proporcionadas.",
            default => ""
        };

        return <<<EOT
Necesito que agregues descripciones breves (máximo 50 caracteres) y categorías específicas a los siguientes platos. Devuelve un array JSON válido donde para cada objeto agregues los campos "description" y "category" a los existentes.

Ejemplo de salida esperada:
[
    {
        "name": "Sopa de Tomate",
        "price": "5.50",
        "description": "Sopa casera de tomates frescos",
        "category": "Sopas"
    },
    {
        "name": "Filete de Ternera",
        "price": "15.00",
        "description": "Jugoso filete a la parrilla",
        "category": "Carnes"
    }
]

Asegúrate de que las categorías sean lo más específicas posibles según el plato (por ejemplo, "Pescados", "Pastas", "Ensaladas").

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
            }

            return [
                'name' => trim($item['name']),
                'price' => preg_replace('/[^0-9\.\-]/', '', $item['price']),
                'description' => trim($item['description'] ?? 'Sin descripción'),
                'category' => trim($item['category'] ?? $this->assignCategory($item['name'], $item['description'], $this->keywords))
            ];
        }, $items);
    }

    private function cleanAndValidateJSON($content)
    {
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
   
}






