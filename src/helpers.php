<?php

use Cradle\Package\System\Schema;

return function($request, $response) {
    //set the schema path
    $config = $this->package('global')->path('config');
    $this->package('global')->path('schema', $config . '/schema')

    /**
     * A helper to manage the schema file system
     */
    ->addMethod('schema', function ($path, array $data = null) {
        static $cache = [];

        //determine file path
        $config = $this->path('schema');
        $file = $config . '/' . $path . '.php';

        //is it already in memory?
        if (!isset($cache[$path])) {
            if (!file_exists($file)) {
                $cache[$path] = [];
            } else {
                //get the data and cache
                $cache[$path] = include($file);
            }
        }

        if (is_null($data)) {
            //return the data
            return $cache[$path];
        }

        //they are trying to write
        //if it is not a folder
        if (!is_dir(dirname($file))) {
            //make a folder
            mkdir(dirname($file), 0777, true);
        }

        //if it is not a file
        if (!file_exists($file)) {
            //make the file
            touch($file);
            chmod($file, 0777);
        }

        $cache[$path] = $data;

        // at any rate, update the config
        $content = "<?php //-->\nreturn " . var_export($cache[$path], true) . ';';
        file_put_contents($file, $content);

        return $this;
    });

    //add helpers
    $handlebars = $this->package('global')->handlebars();

    $handlebars->registerHelper('relations', function (...$args) {
        //resolve the arguments
        $options = array_pop($args);
        $schema = array_shift($args);
        $many = -1;

        if (isset($args[0])) {
            $many = $args[0];
        }

        if (isset($args[1]) && $args[1]) {
            $relations = Schema::i($schema)->getReverseRelations($many);
        } else {
            $relations = Schema::i($schema)->getRelations($many);
        }

        if (!is_numeric($many) && count($relations)) {
            $table = $relations['table'];
            $relations = [$table => $relations];
        }

        //pass suggestion title field for each relation to the template
        foreach ($relations as $name => $relation) {
            $relations[$name]['suggestion_name'] = '_' . $relation['primary2'];
        }

        if (empty($relations)) {
            return $options['inverse']();
        }

        $each = cradle('global')->handlebars()->getHelper('each');

        return $each($relations, $options);
    });

    $handlebars->registerHelper('suggest', function ($schema, $row) {
        return Schema::i($schema)->getSuggestionFormat($row);
    });

    $handlebars->registerHelper('format', function ($schema, $row, $type, $options) {
        $schema = Schema::i($schema);
        $fields = $schema->getFields();

        if ($type !== 'list') {
            $type = 'detail';
        }

        $formats = [];
        foreach ($fields as $name => $field) {
            $format = $field[$type];
            $format['name'] = $name;
            $format['label'] = $field['label'];

            if (!isset($row[$name])) {
                $format['value'] = null;
            } else {
                $format['value'] = $row[$name];
            }

            $format['this'] = $format;

            $formats[] = $options['fn']($format);
        }

        return implode('', $formats);
    });

    $handlebars->registerHelper('schema_row', function ($schema, $row, $key) {
        $schema = Schema::i($schema);

        switch ($key) {
            case 'id':
                return $row[$schema->getPrimaryFieldName()];
            case 'active':
                $key = $schema->getActiveFieldName();

                if ($key === false) {
                    return true;
                }

                if (isset($row[$key])) {
                    return $row[$key];
                }

                break;
            case 'created':
                $key = $schema->getCreatedFieldName();
                if (isset($row[$key])) {
                    return $row[$key];
                }
                break;
            case 'updated':
                $key = $schema->getUpdatedFieldName();
                if (isset($row[$key])) {
                    return $row[$key];
                }
                break;
        }

        return false;
    });

    $handlebars->registerHelper('active', function ($schema, $row, $options) {
        $schemaKey = cradle('global')->handlebars()->getHelper('schema_row');

        if ($schemaKey($schema, $row, 'active')) {
            return $options['fn']();
        }

        return $options['inverse']();
    });

    $handlebars->registerHelper('json_encode', function (...$args) {
        $options = array_pop($args);
        $value = array_shift($args);
        foreach ($args as $arg) {
            if (!isset($value[$arg])) {
                $value = null;
                break;
            }

            $value = $value[$arg];
        }

        if (!$value) {
            return '';
        }

        if (!is_array($value) && !is_object($value)) {
            return $value;
        }

        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    });

    $handlebars->registerHelper('json_pretty', function ($value, $options) {
        return nl2br(str_replace(' ', '&nbsp;', json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)));
    });

    /**
     * Add Template Builder
     */
    $this->package('cradlephp/cradle-system')->addMethod('template', function (
        $type,
        $file,
        array $data = [],
        $partials = [],
        $customFileRoot  = null,
        $customPartialsRoot = null
    ) {
        // get the root directory
        $root =  $customFileRoot;
        $partialRoot = $customPartialsRoot;

        // get the root directory
        $type = ucwords($type);
        $originalRoot =  sprintf('%s/%s/template/', __DIR__, $type);

        if (!$customFileRoot) {
            $root = $originalRoot;
        }

        if (!$customPartialsRoot) {
            $partialRoot =  $originalRoot;
        }

        // check for partials
        if (!is_array($partials)) {
            $partials = [$partials];
        }

        $paths = [];

        foreach ($partials as $partial) {
            //Sample: product_comment => product/_comment
            //Sample: flash => _flash
            $path = str_replace('_', '/', $partial);
            $last = strrpos($path, '/');

            if($last !== false) {
                $path = substr_replace($path, '/_', $last, 1);
            }

            $path = $path . '.html';

            if (strpos($path, '_') === false) {
                $path = '_' . $path;
            }

            $paths[$partial] = $partialRoot . $path;
        }

        $file = $root . $file . '.html';

        //render
        return cradle('global')->template($file, $data, $paths);
    });
};
