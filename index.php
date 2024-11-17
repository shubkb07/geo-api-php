<?php
require 'vendor/autoload.php';

use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Schema;
use GraphQL\GraphQL;

// Database configuration
const DB_CONFIG = [
    'host' => 'srv1640.hstgr.io:3306',
    'user' => 'u788505671_api',
    'password' => 'Shubham07@kb#api',
    'database' => 'u788505671_api'
];

// Table relationships configuration
const RELATIONSHIPS = [
    'cities' => [
        'states' => ['foreign_key' => 'state_id', 'reverse' => true],
        'countries' => ['foreign_key' => 'country_id', 'reverse' => true]
    ],
    'states' => [
        'countries' => ['foreign_key' => 'country_id', 'reverse' => true],
        'cities' => ['foreign_key' => 'state_id', 'reverse' => false]
    ],
    'countries' => [
        'regions' => ['foreign_key' => 'region_id', 'reverse' => true],
        'subregions' => ['foreign_key' => 'subregion_id', 'reverse' => true],
        'states' => ['foreign_key' => 'country_id', 'reverse' => false],
        'cities' => ['foreign_key' => 'country_id', 'reverse' => false]
    ],
    'regions' => [
        'countries' => ['foreign_key' => 'region_id', 'reverse' => false],
        'subregions' => ['foreign_key' => 'region_id', 'reverse' => false]
    ],
    'subregions' => [
        'regions' => ['foreign_key' => 'region_id', 'reverse' => true],
        'countries' => ['foreign_key' => 'subregion_id', 'reverse' => false]
    ]
];

class DatabaseConnection {
    private static $instance = null;
    private $conn;

    private function __construct() {
        $this->conn = new mysqli(
            DB_CONFIG['host'],
            DB_CONFIG['user'],
            DB_CONFIG['password'],
            DB_CONFIG['database']
        );

        if ($this->conn->connect_error) {
            throw new Exception("Connection failed: " . $this->conn->connect_error);
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->conn;
    }
}

class QueryBuilder {
    private $conn;
    private $relationships;

    public function __construct($conn, $relationships) {
        $this->conn = $conn;
        $this->relationships = $relationships;
    }

    public function getTableColumns($table) {
        $columns = [];
        $result = $this->conn->query("SHOW COLUMNS FROM $table");
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
        return $columns;
    }

    public function buildJoinChain($mainTable, $filterTable) {
        $joins = [];
        $visited = [];
        $path = $this->findJoinPath($mainTable, $filterTable, $visited);
        
        if (!$path) return [];

        for ($i = 0; $i < count($path) - 1; $i++) {
            $currentTable = $path[$i];
            $nextTable = $path[$i + 1];
            
            $relationship = $this->relationships[$currentTable][$nextTable] ?? null;
            if (!$relationship) {
                $relationship = $this->relationships[$nextTable][$currentTable] ?? null;
                if ($relationship) {
                    $joins[] = "LEFT JOIN $nextTable ON $currentTable.{$relationship['foreign_key']} = $nextTable.id";
                }
            } else {
                if ($relationship['reverse']) {
                    $joins[] = "LEFT JOIN $nextTable ON $currentTable.{$relationship['foreign_key']} = $nextTable.id";
                } else {
                    $joins[] = "LEFT JOIN $nextTable ON $nextTable.{$relationship['foreign_key']} = $currentTable.id";
                }
            }
        }
        
        return $joins;
    }

    private function findJoinPath($start, $end, &$visited) {
        if ($start === $end) return [$start];
        
        $visited[$start] = true;
        
        foreach ($this->relationships[$start] as $nextTable => $rel) {
            if (!isset($visited[$nextTable])) {
                $path = $this->findJoinPath($nextTable, $end, $visited);
                if ($path) {
                    return array_merge([$start], $path);
                }
            }
        }
        
        return null;
    }

    public function buildQuery($table, $params) {
        $filters = [];
        $joins = [];
        $bindValues = [];
        $processedTables = [];

        foreach ($params as $key => $value) {
            if (strpos($key, 'query_') === 0) {
                $parts = explode('_', $key, 3);
                if (count($parts) === 3) {
                    $filterTable = $parts[1];
                    $field = $parts[2];

                    if ($filterTable !== $table && !isset($processedTables[$filterTable])) {
                        $tableJoins = $this->buildJoinChain($table, $filterTable);
                        $joins = array_merge($joins, $tableJoins);
                        $processedTables[$filterTable] = true;
                    }

                    $fieldValues = explode(',', $value);
                    $placeholders = implode(',', array_fill(0, count($fieldValues), '?'));
                    $filters[] = "$filterTable.$field IN ($placeholders)";
                    $bindValues = array_merge($bindValues, $fieldValues);
                }
            }
        }

        $page = $params['page'] ?? 1;
        $perPage = $params['per_page'] ?? 10;
        $allData = isset($params['all']) && $params['all'] === 'y';
        $filter = $params['filter'] ?? null;

        $selectClause = $this->buildSelectClause($table, $filter);
        $sql = "SELECT DISTINCT $selectClause FROM $table ";

        if (!empty($joins)) {
            $sql .= implode(' ', $joins) . ' ';
        }
        
        if (!empty($filters)) {
            $sql .= " WHERE " . implode(' AND ', $filters);
        }

        if (!$allData) {
            $offset = ($page - 1) * $perPage;
            $sql .= " LIMIT $offset, $perPage";
        }

        return [$sql, $bindValues];
    }

    private function buildSelectClause($table, $filter = null) {
        if (empty($filter)) return "$table.*";

        $validColumns = $this->getTableColumns($table);
        $requestedColumns = array_map('trim', explode(',', $filter));
        $filteredColumns = array_intersect($requestedColumns, $validColumns);

        if (empty($filteredColumns)) return "$table.*";

        return implode(', ', array_map(fn($col) => "$table.$col", $filteredColumns));
    }
}

function renderDocumentation() {
    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Geographic Data API Documentation</title>
    <style>
        :root {
            color-scheme: light dark;
        }

        /* Mobile-first styles with new color scheme */
        :root[data-theme="light"] {
            --primary-color: #ff6f61; /* Vibrant coral for primary color */
            --secondary-color: #ffa600; /* Bright orange for secondary accents */
            --accent-color: #ff4081; /* Hot pink for accents to add vibrancy */
            --highlight-color: #ffd700; /* Gold for highlights */
            --text-color: #333333; /* Neutral dark for text */
            --muted-text-color: #555555; /* Soft gray for muted text */
            --text-outside-endpoint-color: #4a4a4a;
            --code-bg: #fff4e6; /* Light cream for code blocks */
            --light-bg: #ffffff;
            --border-color: #e0e0e0;
            --background: #fdf8f5;
            --highlight-bg: #ffecd2;
        }

        :root[data-theme="dark"] {
            --primary-color: #f59e0b; /* Brighter amber for primary color */
            --secondary-color: #d97706; /* Amber for secondary color */
            --text-color: #f3f4f6; /* Lighter gray for text */
            --muted-text-color: #9ca3af; /* Muted gray for dark mode */
            --text-outside-endpoint-color: #e5e7eb;
            --code-bg: #5b21b6; /* Deep indigo for code blocks */
            --light-bg: #1f2937;
            --border-color: #4b5563;
            --background: #111827;
            --highlight-bg: #7c3aed;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.8;
            color: var(--text-color);
            padding: 1.5rem;
            margin: 0 auto;
            max-width: 100%;
            background-color: var(--background);
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        h1, h2, h3, h4 {
            margin: 1.5rem 0 1rem;
            color: var(--primary-color);
        }

        h1 {
            font-size: 2rem;
            border-bottom: 2px solid var(--primary-color);
            padding-bottom: 0.5rem;
        }

        h2 {
            font-size: 1.5rem;
            margin-top: 2rem;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0.5rem;
        }

        h3 {
            font-size: 1.3rem;
            margin-top: 1.5rem;
            color: var(--secondary-color);
        }

        h4 {
            font-size: 1.1rem;
            margin-top: 1rem;
            color: var(--text-color);
            font-weight: 600;
        }

        p {
            color: var(--muted-text-color);
            margin-bottom: 1rem;
        }

        code {
            background: var(--highlight-bg);
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-family: 'Courier New', Courier, monospace;
            color: var(--text-color);
            font-size: 0.9em;
        }

        pre {
            background: var(--code-bg);
            padding: 1rem;
            border-radius: 8px;
            overflow-x: auto;
            margin: 1rem 0;
            border: 1px solid var(--border-color);
            color: var(--text-color);
            font-size: 0.9em;
        }

        .endpoint {
            background: var(--light-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
            transition: box-shadow 0.3s ease;
        }

        .endpoint:hover {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .method {
            display: inline-block;
            background: var(--primary-color);
            color: #ffffff;
            padding: 0.3rem 0.6rem;
            border-radius: 6px;
            font-weight: bold;
            font-size: 0.9rem;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 1.5rem 0;
            background: var(--light-bg);
        }

        th, td {
            padding: 0.6rem;
            border: 1px solid var(--border-color);
            text-align: left;
            color: var(--text-color);
        }

        th {
            background: var(--highlight-bg);
            font-weight: bold;
        }

        .parameter {
            margin-bottom: 0.8rem;
            padding: 0.6rem;
            background: var(--code-bg);
            border-radius: 6px;
            color: var(--text-color);
            font-size: 0.9rem;
        }

        .parameter-name {
            display: inline-block;
            font-weight: bold;
            color: var(--primary-color);
            margin-right: 0.5rem;
        }

        ul {
            margin: 1rem 0;
            padding-left: 1.5rem;
            color: var(--text-color);
        }

        li {
            margin-bottom: 0.5rem;
            color: var(--text-color);
        }

        .theme-switcher {
            position: fixed;
            top: 1rem;
            right: 1rem;
            display: flex;
            gap: 0.5rem;
            background: var(--light-bg);
            padding: 0.5rem;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
        }

        .theme-btn {
            padding: 0.4rem 1rem;
            border: none;
            border-radius: 6px;
            background: var(--accent-color);
            color: white;
            cursor: pointer;
            font-size: 0.9rem;
            transition: background 0.3s;
        }

        .theme-btn:hover {
            background: var(--secondary-color);
        }

        .theme-btn.active {
            background: var(--secondary-color);
        }

        .github-link {
            display: inline-block;
            margin-top: 1.5rem;
            padding: 0.6rem 1.2rem;
            background: var(--secondary-color);
            color: white;
            text-decoration: none;
            border-radius: 6px;
            transition: background 0.3s;
        }

        .github-link:hover {
            background: var(--secondary-color);
        }

        pre.code-snippet {
    position: relative;
    padding-top: 3rem !important;
}

.copy-button {
    position: absolute;
    top: 0.5rem;
    right: 0.5rem;
    background: var(--light-bg);
    border: 1px solid var(--border-color);
    border-radius: 4px;
    padding: 0.25rem;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    z-index: 1;
}

.copy-button:hover {
    background: var(--highlight-bg);
}

.copy-button svg {
    width: 16px;
    height: 16px;
    color: var(--text-color);
}

.copy-button.copied {
    background: var(--primary-color);
}

.copy-button.copied svg {
    color: white;
}


        /* Tablet styles */
        @media (min-width: 600px) {
            body {
                padding: 2rem;
                max-width: 100%;
            }
            h1 {
                font-size: 2.4rem;
            }
            h2 {
                font-size: 1.8rem;
            }
            h3 {
                font-size: 1.5rem;
            }
        }

        /* Desktop styles */
        @media (min-width: 1024px) {
            body {
                max-width: 1200px;
                padding: 3rem;
            }
            h1 {
                font-size: 2.8rem;
            }
            h2 {
                font-size: 2rem;
            }
            .endpoint {
                padding: 2rem;
            }
        }
    </style>
</head>
<body data-theme="system">
    <div class="theme-switcher">
        <button class="theme-btn" data-theme="system">System</button>
        <button class="theme-btn" data-theme="light">Light</button>
        <button class="theme-btn" data-theme="dark">Dark</button>
    </div>

    <h1>Geographic Data API Documentation</h1>
    
    <p>Welcome to the Geographic Data API documentation. This API provides comprehensive access to geographic data including countries, regions, states, cities, and their relationships. Use this documentation to learn how to interact with our API endpoints effectively.</p>

    <a href="https://github.com/shubkb07/geo-php" target="_blank" class="github-link">View Project on GitHub</a>
    
    <h2>Overview</h2>
    <p>This API allows you to query and retrieve geographic data with support for both REST and GraphQL approaches. You can filter data, paginate results, and perform complex queries across related geographic entities.</p>
    
    <h2>Available Endpoints</h2>
    
    <div class="endpoint">
        <h3><span class="method">GET</span> /?get={table}</h3>
        <p>Retrieve data from specified table with optional filtering and pagination.</p>
        
        <h4>Available Tables:</h4>
        <ul>
            <li>countries - Access country-level data</li>
            <li>regions - Query regional information</li>
            <li>subregions - Get subregional details</li>
            <li>states - Access state/province data</li>
            <li>cities - Query city-level information</li>
        </ul>
        
        <h4>Parameters:</h4>
        <div class="parameter">
            <span class="parameter-name">get</span> (required): Table name to query.
        </div>
        <div class="parameter">
            <span class="parameter-name">page</span> (optional): Page number for pagination (default: 1).
        </div>
        <div class="parameter">
            <span class="parameter-name">per_page</span> (optional): Items per page (default: 10).
        </div>
        <div class="parameter">
            <span class="parameter-name">all</span> (optional): Set to 'y' to retrieve all records.
        </div>
        <div class="parameter">
            <span class="parameter-name">filter</span> (optional): Comma-separated list of columns to return.
        </div>
        <div class="parameter">
            <span class="parameter-name">query_{table}_{field}</span> (optional): Filter by related table fields.
        </div>
    </div>
    
    <div class="endpoint">
        <h3><span class="method">POST</span> / (GraphQL)</h3>
        <p>Execute GraphQL queries for more complex data requirements.</p>
        
        <h4>Example GraphQL Query:</h4>
        <pre class="code-snippet">{
  countries {
    id
    name
    code
  }
}</pre>
    </div>

    <h2>Live Examples</h2>
    <div class="examples-section">
        <h3>Example Queries and Responses</h3>

        <h4>View all countries</h4>
        <a href="?get=countries" target="_blank" class="example-link">/get=countries</a>
        <pre class="code-snippet">[
  {
    "id": 1,
    "name": "United States",
    "code": "US"
  },
  {
    "id": 2,
    "name": "Canada",
    "code": "CA"
  }
]</pre>

        <h4>Get first 10 countries with filtered columns</h4>
        <a href="?get=countries&filter=id,name,code&page=1&per_page=10" target="_blank" class="example-link">/get=countries&filter=id,name,code&page=1&per_page=10</a>
        <pre class="code-snippet">[
  {
    "id": 1,
    "name": "United States",
    "code": "US"
  },
  {
    "id": 2,
    "name": "Canada",
    "code": "CA"
  }
]</pre>

        <h4>Retrieve subregions for a specific region ID</h4>
        <a href="/?get=subregions&query_regions_id=2" target="_blank" class="example-link">/?get=subregions&query_regions_id=2</a>
        <pre class="code-snippet">{
  "subregions": [
    {"id": 1, "name": "Western Europe", "region_id": 2},
    {"id": 2, "name": "Southern Europe", "region_id": 2}
  ]
}</pre>

        <h4>Retrieve all states with country filter</h4>
        <a href="/?get=states&query_countries_id=1" target="_blank" class="example-link">/?get=states&query_countries_id=1</a>
        <pre class="code-snippet">{
  "states": [
    {"id": 1, "name": "California", "country_id": 1},
    {"id": 2, "name": "New York", "country_id": 1}
  ]
}</pre>

        <h4>Retrieve all cities with pagination and specific fields</h4>
        <a href="/?get=cities&filter=id,name&per_page=3&page=2" target="_blank" class="example-link">/?get=cities&filter=id,name&per_page=3&page=2</a>
        <pre class="code-snippet">{
  "cities": [
    {"id": 4, "name": "Chicago"},
    {"id": 5, "name": "Houston"},
    {"id": 6, "name": "Phoenix"}
  ],
  "pagination": {
    "page": 2,
    "per_page": 3,
    "total": 10
  }
}</pre>
        <h4>Retrieve subregions for a specific region ID</h4>
        <a href="/?get=subregions&query_regions_id=2" target="_blank" class="example-link">/?get=subregions&query_regions_id=2</a>
        <pre class="code-snippet">{
  "subregions": [
    {"id": 1, "name": "Western Europe", "region_id": 2},
    {"id": 2, "name": "Southern Europe", "region_id": 2}
  ]
}</pre>

        <h4>Retrieve all states with country filter</h4>
        <a href="/?get=states&query_countries_id=1" target="_blank" class="example-link">/?get=states&query_countries_id=1</a>
        <pre class="code-snippet">{
  "states": [
    {"id": 1, "name": "California", "country_id": 1},
    {"id": 2, "name": "New York", "country_id": 1}
  ]
}</pre>

        <h4>Retrieve all cities with pagination and specific fields</h4>
        <a href="/?get=cities&filter=id,name&per_page=3&page=2" target="_blank" class="example-link">/?get=cities&filter=id,name&per_page=3&page=2</a>
        <pre class="code-snippet">{
  "cities": [
    {"id": 4, "name": "Chicago"},
    {"id": 5, "name": "Houston"},
    {"id": 6, "name": "Phoenix"}
  ],
  "pagination": {
    "page": 2,
    "per_page": 3,
    "total": 10
  }
}</pre>

        <h4>Retrieve All Subregions</h4>
        <a href="?get=subregions" target="_blank" class="example-link">/get=subregions</a>
        <pre class="code-snippet">[
  {
    "id": 1,
    "name": "Southern Asia",
    "region_id": 3
  },
  {
    "id": 2,
    "name": "Northern Europe",
    "region_id": 5
  }
]</pre>

        <h4>GraphQL Query Example</h4>
        <a href="/graphql?query={countries{name,code}}" target="_blank" class="example-link">GraphQL Query</a>
        <pre class="code-snippet">{
  "data": {
    "countries": [
      {
        "name": "United States",
        "code": "US"
      },
      {
        "name": "Canada",
        "code": "CA"
      }
    ]
  }
}</pre>
    </div>
    
    <h2>Response Format</h2>
    <p>All API responses are returned in JSON format. Successful requests return an array of objects containing the requested data.</p>
    
    <h3>Example Response:</h3>
    <pre class="code-snippet">[
  {
    "id": 1,
    "name": "United States",
    "code": "US"
  }
]</pre>

    <h2>Error Handling</h2>
    <p>When an error occurs, the API returns a JSON object with an "error" key containing the error message.</p>
    
    <h3>Example Error:</h3>
    <pre class="code-snippet">{
  "error": "Invalid table requested"
}</pre>

    <h2>Support</h2>
    <p>If you need help or have questions about using the API, please contact our support team at <a href="mailto:geo.php.api@shubkb.com">geo.php.api@shubkb.com</a></p>

    <div class="credits">
        <h2>Credits</h2>
        <p>This API is powered by:</p>
        <ul>
            <li>GraphQL PHP Implementation: <a href="https://github.com/webonyx/graphql-php" target="_blank">webonyx/graphql-php</a></li>
            <li>Geographic Database: <a href="https://github.com/dr5hn/countries-states-cities-database" target="_blank">dr5hn/countries-states-cities-database</a></li>
        </ul>
    </div>

    <script>
        // Theme handling
        const root = document.documentElement;
        const themeBtns = document.querySelectorAll('.theme-btn');
        
        // Function to get system theme
        function getSystemTheme() {
            return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }

        // Function to set theme
        function setTheme(theme) {
            if (theme === 'system') {
                theme = getSystemTheme();
            }
            root.setAttribute('data-theme', theme);
            localStorage.setItem('preferred-theme', theme);
            
            // Update active button
            themeBtns.forEach(btn => {
                btn.classList.toggle('active', btn.dataset.theme === theme);
            });
        }

        // Initialize theme
        function initializeTheme() {
            const savedTheme = localStorage.getItem('preferred-theme');
            if (savedTheme) {
                setTheme(savedTheme);
            } else {
                setTheme('system');
            }
        }

        initializeTheme();

        // Add event listeners to theme buttons
        themeBtns.forEach(btn => {
            btn.addEventListener('click', () => setTheme(btn.dataset.theme));
        });

        // Listen for system theme changes
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
            if (localStorage.getItem('preferred-theme') === 'system') {
                setTheme('system');
            }
        });

        document.addEventListener('DOMContentLoaded', function() {


    // Copy button functionality
            const copyIcon = `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
            </svg>`;
            
            const checkIcon = `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>`;

            // Function to copy text using fallback methods
            function copyTextToClipboard(text) {
                return new Promise((resolve, reject) => {
                    // Try to use the clipboard API first
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(text)
                            .then(resolve)
                            .catch(fallbackCopy);
                    } else {
                        fallbackCopy();
                    }

                    // Fallback for older browsers
                    function fallbackCopy() {
                        try {
                            const textArea = document.createElement('textarea');
                            textArea.value = text;
                            textArea.style.position = 'fixed';
                            textArea.style.top = '0';
                            textArea.style.left = '0';
                            textArea.style.width = '2em';
                            textArea.style.height = '2em';
                            textArea.style.padding = '0';
                            textArea.style.border = 'none';
                            textArea.style.outline = 'none';
                            textArea.style.boxShadow = 'none';
                            textArea.style.background = 'transparent';
                            
                            document.body.appendChild(textArea);
                            textArea.focus();
                            textArea.select();

                            const successful = document.execCommand('copy');
                            document.body.removeChild(textArea);

                            if (successful) {
                                resolve();
                            } else {
                                reject(new Error('Copy command was unsuccessful'));
                            }
                        } catch (err) {
                            reject(err);
                        }
                    }
                });
            }

            // Add copy buttons to code blocks
            const codeBlocks = document.getElementsByTagName('pre');
            
            Array.from(codeBlocks).forEach(pre => {
                pre.classList.add('code-snippet');
                
                const copyButton = document.createElement('button');
                copyButton.className = 'copy-button';
                copyButton.innerHTML = copyIcon;
                copyButton.setAttribute('aria-label', 'Copy code');
                
                pre.appendChild(copyButton);
                
                copyButton.addEventListener('click', () => {
                    const code = pre.textContent
                        .replace(/Copy$/,'')
                        .replace(/^\s+|\s+$/g, '')
                        .replace(new RegExp(copyButton.textContent, 'g'), '');
                    
                    copyTextToClipboard(code)
                        .then(() => {
                            copyButton.innerHTML = checkIcon;
                            copyButton.classList.add('copied');
                            
                            setTimeout(() => {
                                copyButton.innerHTML = copyIcon;
                                copyButton.classList.remove('copied');
                            }, 2000);
                        })
                        .catch(err => {
                            console.error('Failed to copy:', err);
                            alert('Failed to copy code to clipboard');
                        });
                });
            });
        });
    </script>
</body>
</html>
HTML;

    return $html;
}

// Main execution
header('Content-Type: application/json');

try {
    $db = DatabaseConnection::getInstance();
    $conn = $db->getConnection();
    $queryBuilder = new QueryBuilder($conn, RELATIONSHIPS);

    // Display documentation if no parameters are set
    if (empty($_GET) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        header('Content-Type: text/html');
        echo renderDocumentation();
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // GraphQL handling
        $tableTypes = [];
        foreach (RELATIONSHIPS as $tableName => $relations) {
            $fields = [];
            $result = $conn->query("SHOW COLUMNS FROM $tableName");
            while ($row = $result->fetch_assoc()) {
                $fieldType = Type::string();
                if (strpos($row['Type'], 'int') !== false) {
                    $fieldType = Type::int();
                } elseif (strpos($row['Type'], 'decimal') !== false) {
                    $fieldType = Type::float();
                }
                $fields[$row['Field']] = ['type' => $fieldType];
            }

            $tableTypes[$tableName] = new ObjectType([
                'name' => ucfirst($tableName),
                'fields' => $fields
            ]);
        }

        $queryFields = [];
        foreach ($tableTypes as $tableName => $type) {
            $queryFields[$tableName] = [
                'type' => Type::listOf($type),
                'resolve' => function ($root, $args) use ($queryBuilder, $tableName) {
                    list($sql, $bindValues) = $queryBuilder->buildQuery($tableName, $_GET);
                    $stmt = $queryBuilder->getConnection()->prepare($sql);
                    
                    if ($stmt) {
                        if (!empty($bindValues)) {
                            $types = str_repeat("s", count($bindValues));
                            $stmt->bind_param($types, ...$bindValues);
                        }
                        
                        $stmt->execute();
                        $result = $stmt->get_result();
                        return $result->fetch_all(MYSQLI_ASSOC);
                    }
                    return null;
                }
            ];
        }

        $queryType = new ObjectType([
            'name' => 'Query',
            'fields' => $queryFields
        ]);

        $schema = new Schema([
            'query' => $queryType
        ]);

        // Handle GraphQL request
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        $query = $input['query'] ?? null;
        $variableValues = $input['variables'] ?? null;

        if ($query) {
            $result = GraphQL::executeQuery($schema, $query, null, null, $variableValues);
            echo json_encode($result->toArray());
        } else {
            echo json_encode(['errors' => [['message' => 'No GraphQL query provided']]]);
        }
    } else {
        // REST API handling
        $get = $_GET['get'] ?? null;

        if (isset(RELATIONSHIPS[$get])) {
            list($sql, $bindValues) = $queryBuilder->buildQuery($get, $_GET);
            $stmt = $conn->prepare($sql);
            
            if ($stmt) {
                if (!empty($bindValues)) {
                    $types = str_repeat("s", count($bindValues));
                    $stmt->bind_param($types, ...$bindValues);
                }
                
                $stmt->execute();
                $result = $stmt->get_result();
                echo json_encode($result->fetch_all(MYSQLI_ASSOC));
            } else {
                echo json_encode(['error' => 'Database query failed']);
            }
        } else {
            echo json_encode(['error' => 'Invalid table requested']);
        }
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
