<?php
// =====================================================
// AI ECOSORT
// PHP + HTML + CSS + OLLAMA
// Model: qwen3:4b
// =====================================================

// Ollama settings
$ollamaUrl = "http://127.0.0.1:11434/api/generate";
$model = "qwen3:4b";

$item = "";
$selectedMaterials = [];
$result = null;
$error = "";

// Available material hint buttons
$materialOptions = [
    "Plastic",
    "Paper / Cardboard",
    "Glass",
    "Metal",
    "Organic",
    "Wood",
    "Textile",
    "Electronic / Battery"
];

// =====================================================
// 1. SECURITY / DISPLAY FUNCTION
// =====================================================

function cleanText($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

// =====================================================
// 2. OLLAMA AI CLASSIFICATION
// =====================================================

function askOllama($item, $materials, $ollamaUrl, $model) {

    $materialContext = !empty($materials)
        ? implode(", ", $materials)
        : "Not specified by user";

    $prompt = <<<PROMPT
You are AI EcoSort, an environmental waste classification assistant.

Analyse this waste item:
"$item"

User-selected material hint(s):
"$materialContext"

AVAILABLE CATEGORIES:
- Paper/Cardboard
- Plastic
- Glass
- Metal
- Organic
- General/Residual
- Special Waste
- Uncertain

CLASSIFICATION KNOWLEDGE:

Organic:
Food scraps, fruit peels, vegetable scraps, eggshells, untreated wood scraps and suitable garden waste.

Special Waste:
Batteries, vapes, electronic devices, power banks, chemicals, pesticides, medical waste and hazardous materials.

General/Residual:
Used condoms, sanitary pads, tampons, diapers, used tissues, contaminated hygiene products, greasy/food-soiled paper, composite/non-recyclable textiles, and treated/painted wood.

Paper/Cardboard:
Clean, dry newspapers, office paper, magazines and cardboard packaging.

Plastic:
Clean plastic bottles, plastic bags, plastic containers and plastic packaging.

Metal:
Aluminium cans, steel cans and scrap metal.

Glass:
Glass bottles, glass jars and glass containers.

IMPORTANT RULES:

1. Use both the item description and the user-selected material hint(s) to classify the waste accurately.
2. If the user's selected material conflicts with the actual item (e.g., selecting "Plastic" for a "banana peel"), trust the actual item description and clarify it in uncertainty_note.
3. Food or grease contamination ruins paper/cardboard recyclability -> classify heavily soiled paper/cardboard as General/Residual.
4. Do not classify an item as Uncertain just because its description is short.
5. A vape or battery is always Special Waste and must set special_warning to true.
6. Used condoms and contaminated hygiene products are General/Residual, NOT Organic.
7. For products containing multiple materials, identify the main waste category and explain the other components.
8. If an item is genuinely unclear, use Uncertain and ask a clarification question.
9. Never invent local recycling rules or recommend unsafe handling.

EXAMPLES OF CORRECT CLASSIFICATION:

Input: "greasy pizza box" | Material hint: "Paper / Cardboard"
Output: {"reasoning": "Cardboard is normally recyclable, but grease and cheese residue contaminate paper fibers and cannot be pulped.", "category": "General/Residual", "confidence": "High", "uncertainty_note": "Greasy or food-soiled cardboard belongs in general waste rather than paper recycling.", "handling_advice": ["Tear off any clean, dry lid sections for paper recycling if possible.", "Place the greasy bottom half in general waste."], "recycling_reuse": ["Do not place food-soiled cardboard in recycling bins."], "environmental_tip": "Keeping food residue out of paper bins prevents entire batches of recycled paper from being spoiled.", "special_warning": false, "clarification_question": ""}

Input: "apple core" | Material hint: "Metal"
Output: {"reasoning": "User selected Metal, which conflicts with the item description. An apple core is biodegradable food waste.", "category": "Organic", "confidence": "High", "uncertainty_note": "Note: You selected Metal, but an apple core is organic food waste.", "handling_advice": ["Separate the apple core from plastic packaging or stickers."], "recycling_reuse": ["Add to a home compost bin or organic waste collection."], "environmental_tip": "Composting fruit scraps reduces methane emissions from landfills.", "special_warning": false, "clarification_question": ""}

CONFIDENCE:
- High: The item is clearly identified.
- Medium: The likely category is known but details are missing.
- Low: The item cannot be identified reliably.

Return ONLY valid JSON in this exact structure. Fill out "reasoning" FIRST before deciding the category:

{
  "reasoning": "Step-by-step analysis of the item's material, contamination, and whether the user's material hint matches.",
  "category": "Organic",
  "confidence": "High",
  "uncertainty_note": "The item is identified as food waste.",
  "handling_advice": [
    "Separate the item from non-organic waste."
  ],
  "recycling_reuse": [
    "Consider composting if suitable."
  ],
  "environmental_tip": "Composting suitable food waste can reduce landfill waste.",
  "special_warning": false,
  "clarification_question": ""
}

Use arrays for handling_advice and recycling_reuse.
Use true or false for special_warning.
Return JSON only.
PROMPT;

    $data = [
        "model" => $model,
        "prompt" => $prompt,
        "stream" => false,
        "think" => false,
        "format" => "json",
        "options" => [
            "temperature" => 0.1
        ]
    ];

    $ch = curl_init($ollamaUrl);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($response === false) {
        throw new Exception("Could not connect to Ollama: " . $curlError);
    }

    if ($httpCode !== 200) {
        throw new Exception("Ollama returned HTTP error " . $httpCode);
    }

    $apiData = json_decode($response, true);

    if (!isset($apiData["response"])) {
        throw new Exception("Ollama did not return a valid response.");
    }

    $aiResult = json_decode($apiData["response"], true);

    if (!is_array($aiResult)) {
        throw new Exception("AI returned invalid JSON. Please try again.");
    }

    $allowedCategories = [
        "Paper/Cardboard",
        "Plastic",
        "Glass",
        "Metal",
        "Organic",
        "General/Residual",
        "Special Waste",
        "Uncertain"
    ];

    if (
        !isset($aiResult["category"]) ||
        !in_array($aiResult["category"], $allowedCategories, true)
    ) {
        $aiResult["category"] = "Uncertain";
    }

    if (
        !isset($aiResult["confidence"]) ||
        !in_array($aiResult["confidence"], ["High", "Medium", "Low"], true)
    ) {
        $aiResult["confidence"] = "Low";
    }

    foreach (["handling_advice", "recycling_reuse"] as $field) {
        if (!isset($aiResult[$field]) || !is_array($aiResult[$field])) {
            $aiResult[$field] = [];
        }
    }

    $aiResult["reasoning"] = $aiResult["reasoning"] ?? "";
    $aiResult["uncertainty_note"] = $aiResult["uncertainty_note"] ?? "";
    $aiResult["environmental_tip"] = $aiResult["environmental_tip"] ?? "";
    $aiResult["clarification_question"] = $aiResult["clarification_question"] ?? "";
    $aiResult["special_warning"] = !empty($aiResult["special_warning"]);

    return $aiResult;
}

// =====================================================
// 3. HANDLE FORM SUBMISSION
// =====================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $item = trim($_POST["waste_item"] ?? "");
    $rawMaterials = $_POST["materials"] ?? [];

    if (is_array($rawMaterials)) {
        $selectedMaterials = array_values(
            array_intersect($rawMaterials, $materialOptions)
        );
    }

    if ($item === "") {
        $error = "Please enter a waste item before analysing.";
    } elseif (strlen($item) > 500) {
        $error = "Please keep your description below 500 characters.";
    } elseif (!function_exists("curl_init")) {
        $error = "PHP cURL is not enabled. Enable it in php.ini.";
    } else {
        try {
            $result = askOllama($item, $selectedMaterials, $ollamaUrl, $model);
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI EcoSort | Smart Waste Sorting</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #f4f8f3;
            color: #263b32;
            min-height: 100vh;
        }

        header {
            background: white;
            padding: 20px 8%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #e5eee5;
        }

        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #217a50;
        }

        .logo span {
            color: #263b32;
        }

        .header-tag {
            color: #668174;
            font-size: 13px;
        }

        .hero {
            text-align: center;
            padding: 65px 20px 35px;
        }

        .hero-label {
            display: inline-block;
            background: #e0f2e6;
            color: #217a50;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 20px;
        }

        .hero h1 {
            font-size: 42px;
            margin-bottom: 15px;
            color: #1d392b;
        }

        .hero h1 span {
            color: #27945b;
        }

        .hero p {
            max-width: 600px;
            margin: auto;
            line-height: 1.7;
            color: #6c8175;
            font-size: 15px;
        }

        .container {
            max-width: 850px;
            margin: 0 auto 60px;
            padding: 0 20px;
        }

        .sort-card {
            background: white;
            padding: 32px;
            border-radius: 18px;
            box-shadow: 0 8px 30px rgba(35, 75, 48, 0.07);
            border: 1px solid #e6eee7;
        }

        .sort-card h2 {
            font-size: 21px;
            margin-bottom: 10px;
        }

        .description {
            color: #7b8d82;
            font-size: 14px;
            margin-bottom: 25px;
            line-height: 1.6;
        }

        label.field-label {
            display: block;
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        textarea {
            width: 100%;
            min-height: 115px;
            padding: 16px;
            border: 1px solid #dce8de;
            border-radius: 12px;
            font-family: inherit;
            font-size: 15px;
            resize: vertical;
            outline: none;
            color: #263b32;
            background: #fbfdfb;
        }

        textarea:focus {
            border-color: #27945b;
            box-shadow: 0 0 0 3px rgba(39, 148, 91, 0.1);
        }

        .hint {
            color: #91a096;
            font-size: 12px;
            margin-top: 8px;
        }

        .material-section {
            margin-top: 22px;
        }

        .material-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }

        .material-chip {
            cursor: pointer;
            user-select: none;
        }

        .material-chip input[type="checkbox"] {
            display: none;
        }

        .material-chip span {
            display: inline-block;
            padding: 9px 15px;
            border-radius: 25px;
            border: 1px solid #d5e4d8;
            background: #f7fbf8;
            color: #3b5949;
            font-size: 13px;
            font-weight: bold;
            transition: all 0.18s ease;
        }

        .material-chip:hover span {
            border-color: #27945b;
            background: #edf7f0;
        }

        .material-chip input[type="checkbox"]:checked + span {
            background: #258b54;
            color: white;
            border-color: #258b54;
            box-shadow: 0 3px 10px rgba(37, 139, 84, 0.2);
        }

        button[type="submit"] {
            width: 100%;
            margin-top: 25px;
            padding: 15px;
            border: none;
            border-radius: 10px;
            background: #258b54;
            color: white;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        button[type="submit"]:hover {
            background: #1d7044;
        }

        button[type="submit"]:disabled {
            background: #7cb394;
            cursor: not-allowed;
        }

        .error {
            background: #fff0ee;
            color: #b43b31;
            border: 1px solid #f5d0cb;
            padding: 15px;
            border-radius: 10px;
            margin-top: 22px;
            line-height: 1.5;
            font-size: 14px;
        }

        .result {
            margin-top: 30px;
            background: white;
            border: 1px solid #dcebe0;
            border-radius: 18px;
            overflow: hidden;
        }

        .result-header {
            background: #e8f5eb;
            padding: 24px;
        }

        .result-header small {
            color: #518064;
            font-weight: bold;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .result-header h2 {
            color: #216c42;
            font-size: 25px;
            margin-top: 8px;
        }

        .confidence {
            display: inline-block;
            margin-top: 12px;
            background: white;
            color: #39744d;
            padding: 7px 12px;
            border-radius: 20px;
            font-size: 12px;
            border: 1px solid #d4e8d9;
        }

        .result-body {
            padding: 25px;
        }

        .section {
            margin-bottom: 25px;
        }

        .section h3 {
            font-size: 16px;
            margin-bottom: 12px;
            color: #285c3d;
        }

        .section ul {
            padding-left: 21px;
            color: #62766a;
            line-height: 1.8;
            font-size: 14px;
        }

        .section p {
            color: #62766a;
            line-height: 1.8;
            font-size: 14px;
        }

        .reasoning-box {
            background: #f5f9f6;
            border: 1px solid #dce8df;
            padding: 14px 16px;
            border-radius: 10px;
            color: #466152;
            font-size: 13.5px;
            line-height: 1.65;
        }

        .warning {
            background: #fff7e5;
            border: 1px solid #f1dfad;
            padding: 16px;
            border-radius: 10px;
            margin-bottom: 25px;
            color: #805d17;
            font-size: 14px;
            line-height: 1.7;
        }

        .tip {
            background: #eef8f0;
            border-left: 4px solid #29945b;
            padding: 17px;
            border-radius: 8px;
            color: #416c50;
            line-height: 1.7;
            font-size: 14px;
        }

        .disclaimer {
            margin-top: 22px;
            padding-top: 18px;
            border-top: 1px solid #edf1ed;
            color: #89978e;
            font-size: 12px;
            line-height: 1.7;
        }

        footer {
            text-align: center;
            color: #92a197;
            font-size: 12px;
            padding: 25px 15px;
        }

        @media (max-width: 600px) {
            header {
                padding: 18px 5%;
            }

            .header-tag {
                display: none;
            }

            .hero {
                padding-top: 45px;
            }

            .hero h1 {
                font-size: 32px;
            }

            .sort-card {
                padding: 22px;
            }

            .result-body {
                padding: 20px;
            }
        }
    </style>
</head>
<body>

<header>
    <div class="logo">
        ♻ Eco<span>Sort</span>
    </div>
    <div class="header-tag">
        AI-Powered Waste Sorting Assistant
    </div>
</header>

<section class="hero">
    <div class="hero-label">
        🌱 AI FOR A GREENER FUTURE
    </div>
    <h1>
        Sort Smarter. <span>Live Greener.</span>
    </h1>
    <p>
        Not sure how to dispose of your waste?
        Let AI EcoSort help you identify the waste category
        and discover better ways to handle it.
    </p>
</section>

<main class="container">

    <div class="sort-card">
        <h2>♻ Analyse Your Waste</h2>
        <p class="description">
            Enter the name or description of an item and optionally select its material(s).
            Our AI model will analyse it and provide sorting guidance.
        </p>

        <form id="sortForm" method="POST" action="">
            <label class="field-label" for="waste_item">
                What item do you want to sort?
            </label>

            <textarea
                id="waste_item"
                name="waste_item"
                maxlength="500"
                placeholder="Example: An empty takeaway container with leftover sauce..."
                required><?php echo cleanText($item); ?></textarea>

            <p class="hint">
                Be as specific as possible for a more useful result.
            </p>

            <div class="material-section">
                <label class="field-label">
                    Select Material Type(s) <span style="font-weight: normal; color: #7b8d82;">(Optional)</span>
                </label>

                <div class="material-grid">
                    <?php foreach ($materialOptions as $mat): ?>
                        <?php $isChecked = in_array($mat, $selectedMaterials, true); ?>
                        <label class="material-chip">
                            <input
                                type="checkbox"
                                name="materials[]"
                                value="<?php echo cleanText($mat); ?>"
                                <?php echo $isChecked ? "checked" : ""; ?>
                            >
                            <span><?php echo cleanText($mat); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <button id="submitBtn" type="submit">
                Analyse Waste →
            </button>
        </form>

        <?php if ($error !== ""): ?>
            <div class="error">
                <strong>Something went wrong:</strong><br>
                <?php echo cleanText($error); ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($result !== null): ?>
        <div class="result">
            <div class="result-header">
                <small>AI Classification Result</small>
                <h2>
                    <?php echo cleanText($result["category"]); ?>
                </h2>
                <span class="confidence">
                    Confidence: <?php echo cleanText($result["confidence"]); ?>
                </span>

                <?php if (!empty($result["uncertainty_note"])): ?>
                    <p style="margin-top:12px; color:#557461; line-height:1.6;">
                        <?php echo cleanText($result["uncertainty_note"]); ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="result-body">

                <?php if (!empty($result["reasoning"])): ?>
                    <div class="section">
                        <h3>🧠 AI Analysis &amp; Reasoning</h3>
                        <div class="reasoning-box">
                            <?php echo cleanText($result["reasoning"]); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (
                    $result["category"] === "Uncertain" &&
                    !empty($result["clarification_question"])
                ): ?>
                    <div class="warning">
                        <strong>More information needed</strong><br>
                        <?php echo cleanText($result["clarification_question"]); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($result["special_warning"])): ?>
                    <div class="warning">
                        <strong>⚠ Special Waste Warning</strong><br>
                        This item may require special handling.
                        Do not place it in ordinary recycling bins.
                        Check official local authority or specialist
                        disposal instructions.
                    </div>
                <?php endif; ?>

                <div class="section">
                    <h3>🧤 Handling Advice</h3>
                    <?php if (!empty($result["handling_advice"])): ?>
                        <ul>
                            <?php foreach ($result["handling_advice"] as $advice): ?>
                                <li><?php echo cleanText($advice); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p>No handling advice available.</p>
                    <?php endif; ?>
                </div>

                <div class="section">
                    <h3>♻ Recycling &amp; Reuse Suggestions</h3>
                    <?php if (!empty($result["recycling_reuse"])): ?>
                        <ul>
                            <?php foreach ($result["recycling_reuse"] as $suggestion): ?>
                                <li><?php echo cleanText($suggestion); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p>No recycling suggestions available.</p>
                    <?php endif; ?>
                </div>

                <div class="section">
                    <h3>🌍 Environmental Tip</h3>
                    <div class="tip">
                        <?php echo cleanText($result["environmental_tip"]); ?>
                    </div>
                </div>

                <div class="disclaimer">
                    <strong>Important:</strong>
                    AI EcoSort provides general educational guidance.
                    Waste collection and recycling rules may differ by
                    location. Always follow official local authority
                    instructions, especially for hazardous or special waste.
                </div>

            </div>
        </div>
    <?php endif; ?>

</main>

<footer>
    AI EcoSort © <?php echo date("Y"); ?> |
    Promoting Responsible Waste Management
</footer>

<script>
    document.getElementById("sortForm").addEventListener("submit", function () {
        const btn = document.getElementById("submitBtn");
        btn.disabled = true;
        btn.textContent = "Analysing with AI EcoSort...";
    });
</script>

</body>
</html>