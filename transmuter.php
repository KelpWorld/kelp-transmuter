<?php
require_once __DIR__ . '/vendor/autoload.php';

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PhpParser\PrettyPrinter;
use Symfony\Component\Yaml\Yaml;

class FunctionCollector extends NodeVisitorAbstract
{
    public $classMethods = [];
    public $functions = [];
    public $functionMappings;
    private $currentFile;

    public function __construct($currentFile, $functionMappings)
    {
        $this->currentFile = $currentFile;
        $this->functionMappings = $functionMappings;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Function_) {
            $functionName = $node->name->toString();
            // Avoid collecting duplicate functions
            if (!isset($this->functions[$functionName])) {
                // Store the function node along with its file path
                $this->functions[$functionName] = [
                    'node' => $node,
                    'file' => $this->currentFile
                ];
            }
            
            // Check if the function is in the mappings
            if (!isset($this->functionMappings[$functionName])) {
                // Create default mapping
                $this->functionMappings[$functionName] = [
                    'namespace' => 'Kelp',
                    'class' => 'Misc',
                    'method' => $functionName,
                ];
            }

            $mapping = $this->functionMappings[$functionName];

            // Transform function to method
            $methodNode = new Node\Stmt\ClassMethod(
                $mapping['method'],
                [
                    'flags' => Node\Stmt\Class_::MODIFIER_PUBLIC | Node\Stmt\Class_::MODIFIER_STATIC,
                    'params' => $node->getParams(),
                    'stmts' => $node->getStmts(),
                    'returnType' => $node->getReturnType(),
                    'attrGroups' => $node->attrGroups,
                ]
            );

            // Organize method under class and namespace
            $nsKey = $mapping['namespace'];
            $classKey = $mapping['class'];

            if (!isset($this->classMethods[$nsKey])) {
                $this->classMethods[$nsKey] = [];
            }
            if (!isset($this->classMethods[$nsKey][$classKey])) {
                $this->classMethods[$nsKey][$classKey] = [];
            }

            $this->classMethods[$nsKey][$classKey][] = $methodNode;
        }
    }
}

class ClassCollector extends NodeVisitorAbstract
{
    public $classes = [];
    public $classMappings;
    public $namespacedClasses = [];
    private $currentFile;

    public function __construct($currentFile, $classMappings)
    {
        $this->currentFile = $currentFile;
        $this->classMappings = $classMappings;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Class_) {
            $className = $node->name->toString();
            // Avoid collecting duplicate classes
            if (!isset($this->classes[$className])) {
                // Store the class node along with its file path
                $this->classes[$className] = [
                    'node' => $node,
                    'file' => $this->currentFile
                ];
            }

            // Check if the class is in the mappings
            if (!isset($this->classMappings[$className])) {
                // Create default mapping
                $this->classMappings[$className] = [
                    'namespace' => 'Kelp',
                    'class' => $className,
                ];
            }

            $mapping = $this->classMappings[$className];

            // Update class name
            $node->name = new Node\Identifier($mapping['class']);

            // **Update extended class name if needed**
            if ($node->extends !== null) {
                $extendedClassName = $node->extends->toString();

                // Check if extended class is in the mappings
                if (isset($this->classMappings[$extendedClassName])) {
                    $extendedClassMapping = $this->classMappings[$extendedClassName];

                    // Build fully qualified name for the new extended class
                    $newExtendedClassName = $extendedClassMapping['namespace'] . '\\' . $extendedClassMapping['class'];

                    // Update the node
                    $node->extends = new Node\Name\FullyQualified($newExtendedClassName);
                }
            }

            // Organize class under namespace
            $nsKey = $mapping['namespace'];

            if (!isset($this->namespacedClasses[$nsKey])) {
                $this->namespacedClasses[$nsKey] = [];
            }
            $this->namespacedClasses[$nsKey][] = $node;
        }
    }
}

// Directory containing PHP files
$directory = '/Users/austin/Documents/kelp-transmuter/wordpress';
$mappings = [];

// Read both function and class mappings
if ( file_exists('mappings.yaml')) {
    $mappings = Yaml::parseFile('mappings.yaml');
}
if ( empty( $mappings ) ) {
    $mappings = [
        'functions' => [],
        'classes' => [],
    ];
}
$functionMappings = $mappings['functions'] ?? [];
$classMappings = $mappings['classes'] ?? [];

// Generate PHP code for the bindings.php file using string interpolation
$bindingsCode = "<?php\n\n";

// Initialize parser and traverser
$parser = (new ParserFactory)->createForVersion(PhpVersion::fromString('7.4'));
$prettyPrinter = new PrettyPrinter\Standard();

$allFunctions = [];
$functionNames = [];
$classMethods = [];
$allClasses = [];
$classNames = [];
$namespacedClasses = [];

// Collect all PHP files in the directory recursively
$directoryIterator = new RecursiveDirectoryIterator($directory);
$iterator = new RecursiveIteratorIterator($directoryIterator);
$phpFiles = new RegexIterator($iterator, '/\.php$/i');

// Define an array of files to exclude
$excludeFiles = [
    'noop.php',
    'wp-includes/sodium_compat',
    'wp-includes/ID3',
    'wp-includes/Requests',
    'wp-includes/class-requests.php',
    'wp-includes/PHPMailer',
    'wp-includes/class-phpmailer.php',
    'wp-includes/SimplePie',
    'wp-includes/class-simplepie.php',
    'wp-includes/cache-compat.php',
    'wp-content/',
];

foreach ($phpFiles as $file) {
    $filePath = $file->getRealPath();

    $excluded = false;
    // Optionally exclude certain files
    foreach ($excludeFiles as $excludeFile) {
        if (strpos($filePath, $excludeFile) !== false) {
            $excluded = true;
            continue;
        }
    }

    if ($excluded) {
        continue;
    }

    try {
        $code = file_get_contents($filePath);

        // Perform the replacement for "require ABSPATH" with "// require ABSPATH"
        $code = preg_replace('/\brequire(?:_once)?\s+ABSPATH\b/', '// $0', $code);
        $code = str_replace('WordPress', 'Kelp', $code);
        $code = str_replace('Howdy', 'Hey', $code);
        $code = str_replace('wp-login.php', 'admin/', $code);
        if (str_contains($filePath, 'load.php')) {
            $code = str_replace('/wp-admin/install.php', '/admin/install/', $code);
        }

        $stmts = $parser->parse($code);

        if ($stmts === null) {
            continue;
        }

        $traverser = new NodeTraverser();
        $functionCollector = new FunctionCollector($filePath, $functionMappings);
        $traverser->addVisitor(new PhpParser\NodeVisitor\NameResolver());
        $traverser->addVisitor($functionCollector);
        $traverser->traverse($stmts);

        $classTraverser = new NodeTraverser();
        $classCollector = new ClassCollector($filePath, $classMappings);
        $classTraverser->addVisitor(new PhpParser\NodeVisitor\NameResolver());
        $classTraverser->addVisitor($classCollector);
        $classTraverser->traverse($stmts);

        foreach ($functionCollector->functions as $functionName => $data) {
            if (!isset($functionNames[$functionName])) {
                $allFunctions[$functionName] = $data['node'];
                $functionNames[$functionName] = $data['file'];
            } else {
                // Optionally log or handle duplicate functions
                // For example, you can compare the file paths or function bodies
            }
        }
        // Accumulate class methods
        foreach ($functionCollector->classMethods as $nsKey => $classes) {
            if (!isset($classMethods[$nsKey])) {
                $classMethods[$nsKey] = [];
            }
            foreach ($classes as $classKey => $methods) {
                if (!isset($classMethods[$nsKey][$classKey])) {
                    $classMethods[$nsKey][$classKey] = [];
                }
                $classMethods[$nsKey][$classKey] = array_merge($classMethods[$nsKey][$classKey], $methods);
            }
        }
         // Accumulate classes
        foreach ($classCollector->classes as $className => $data) {
            if (!isset($classNames[$className])) {
                $allClasses[$className] = $data['node'];
                $classNames[$className] = $data['file'];
            }
        }
        // Accumulate namespaced classes
        foreach ($classCollector->namespacedClasses as $nsKey => $classes) {
            if (!isset($namespacedClasses[$nsKey])) {
                $namespacedClasses[$nsKey] = [];
            }
            foreach ($classes as $classNode) {
                $namespacedClasses[$nsKey][] = $classNode;
            }
        }
        // Update the main functionMappings with new mappings
        $functionMappings = array_merge($functionMappings, $functionCollector->functionMappings);
        $classMappings = array_merge($classMappings, $classCollector->classMappings);
    } catch (Error $e) {
        echo 'Parse Error in file ', $filePath, ': ', $e->getMessage(), "\n";
    }
}

// Get the current date
$currentDate = date('M jS Y'); // e.g., "Oct 4th 2024"

// Initialize arrays to hold outdated mappings
$outdatedFunctionMappings = [];
$outdatedClassMappings = [];

// Identify and remove outdated function mappings
foreach ($functionMappings as $functionName => $mapping) {
    if (!isset($functionNames[$functionName])) {
        // Function is not found in the codebase, so it's outdated
        unset($functionMappings[$functionName]);

        // Add to outdated functions with removal date
        $mapping['removed'] = $currentDate;
        $outdatedFunctionMappings[$functionName] = $mapping;
    }
}

// Identify and remove outdated class mappings
foreach ($classMappings as $className => $mapping) {
    if (!isset($classNames[$className])) {
        // Class is not found in the codebase, so it's outdated
        unset($classMappings[$className]);

        // Add to outdated classes with removal date
        $mapping['removed'] = $currentDate;
        $outdatedClassMappings[$className] = $mapping;
    }
}

// Read existing outdated mappings from the YAML file
$outdatedMappings = $mappings['outdated'] ?? ['functions' => [], 'classes' => []];

$existingOutdatedFunctionMappings = $outdatedMappings['functions'] ?? [];
$existingOutdatedClassMappings = $outdatedMappings['classes'] ?? [];

// Merge existing and new outdated mappings
$outdatedFunctionMappings = array_merge($existingOutdatedFunctionMappings, $outdatedFunctionMappings);
$outdatedClassMappings = array_merge($existingOutdatedClassMappings, $outdatedClassMappings);

// Generate code for each class using the accumulated class methods
foreach ($classMethods as $namespace => $classes) {
    foreach ($classes as $className => $methods) {
        $classNode = new Node\Stmt\Class_($className, [
            'stmts' => $methods,
        ]);

        $namespaceNode = new Node\Stmt\Namespace_(new Node\Name($namespace), [
            $classNode,
        ]);

        $code = $prettyPrinter->prettyPrintFile([$namespaceNode]);

        // Determine the output file path
        $outputDir = __DIR__ . '/build';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }
        if (!is_dir($outputDir . '/app')) {
            mkdir($outputDir . '/app', 0777, true);
        }
        $outputFile = $outputDir . '/app/' . $className . '.php';
        echo "Generating $outputFile\n";

        // Save the generated code to the file
        file_put_contents($outputFile, $code);
    }
}

foreach ($namespacedClasses as $namespace => $classes) {
    foreach ($classes as $classNode) {
        $className = $classNode->name->toString();

        $namespaceNode = new Node\Stmt\Namespace_(new Node\Name($namespace), [
            $classNode,
        ]);

        $code = $prettyPrinter->prettyPrintFile([$namespaceNode]);
        $directory = "";

        // Retrieve namespace subdirectory
        $parts = explode('\\', $namespace);
        
        // Check if there's more than one part (i.e., there is a subfolder)
        if (count($parts) > 1) {
            array_shift( $parts );
            $directory = "/" . implode( "/", $parts );
        }

        // Determine the output file path
        $outputDir = __DIR__ . '/build';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }
        if (!is_dir("{$outputDir}/app{$directory}/")) {
            mkdir("{$outputDir}/app{$directory}/", 0777, true);
        }
        $outputFile = "{$outputDir}/app{$directory}/{$className}.php";
        echo "Generating $outputFile ($namespace)\n";

        // Save the generated code to the file
        file_put_contents($outputFile, $code);
    }
}

// Sort function mappings by namespace, class, and method
uasort($functionMappings, function ($a, $b) {
    // Compare namespaces
    $namespaceComparison = strcmp($a['namespace'], $b['namespace']);
    if ($namespaceComparison !== 0) {
        return $namespaceComparison;
    }

    // Namespaces are equal, compare classes
    $classComparison = strcmp($a['class'], $b['class']);
    if ($classComparison !== 0) {
        return $classComparison;
    }

    // Classes are equal, compare methods
    return strcmp($a['method'], $b['method']);
});

// Write updated function mappings back to mappings.yaml
$combinedMappings = [
    'functions' => $functionMappings,
    'classes' => $classMappings,
    'outdated' => [
        'functions' => $outdatedFunctionMappings,
        'classes' => $outdatedClassMappings,
    ],
];

// Write updated mappings back to mappings.yaml
$yaml = Yaml::dump($combinedMappings, 4, 2);
file_put_contents('mappings.yaml', $yaml);

$builtInFunctions = get_defined_functions()['internal'];
$builtInFunctions = array_map('strtolower', $builtInFunctions); // Normalize to lowercase

foreach ($functionMappings as $functionName => $mapping) {
    $namespace = $mapping['namespace'];
    $class = $mapping['class'];
    $method = $mapping['method'];
    // Check if the function is a built-in function
    if (in_array(strtolower($functionName), $builtInFunctions)) {
        // Skip built-in functions
        continue;
    }

    $bindingsCode .= <<<EOD
function {$functionName}(...\$args) {
    return {$namespace}\\{$class}::{$method}(...\$args);
}

EOD;
    $bindingsCode .= "\n";
}

foreach ($classMappings as $originalClassName => $mapping) {
    $namespace = $mapping['namespace'];
    $class = $mapping['class'];

    $newClassFullName = "{$namespace}\\{$class}";

    $bindingsCode .= "class_alias('{$newClassFullName}', '{$originalClassName}');\n";
}

// Determine the output file path for bindings.php
$bindingsFile = __DIR__ . '/build/bindings.php';
echo "Generating $bindingsFile\n";

// Save the generated bindings code to the file
file_put_contents($bindingsFile, $bindingsCode);
