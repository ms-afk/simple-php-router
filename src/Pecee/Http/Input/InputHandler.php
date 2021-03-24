<?php

namespace Pecee\Http\Input;

use Pecee\Exceptions\InvalidArgumentException;
use Pecee\Http\Request;

class InputHandler
{
    /**
     * @var array
     */
    protected $get = [];

    /**
     * @var array
     */
    protected $post = [];

    /**
     * @var array
     */
    protected $file = [];

    /**
     * @var Request
     */
    protected $request;

    /**
     * Original post variables
     * @var array
     */
    protected $originalPost = [];

    /**
     * Original get/params variables
     * @var array
     */
    protected $originalParams = [];

    /**
     * Get original file variables
     * @var array
     */
    protected $originalFile = [];

    /**
     * Input constructor.
     * @param Request $request
     */
    public function __construct(Request $request)
    {
        $this->request = $request;

        $this->parseInputs();
    }

    /**
     * Parse input values
     *
     */
    public function parseInputs(): void
    {
        /* Parse get requests */
        if (\count($_GET) !== 0) {
            $this->originalParams = $_GET;
            $this->get = $this->parseInputItem($this->originalParams);
        }

        /* Parse post requests */
        $this->originalPost = $_POST;

        if (\in_array($this->request->getMethod(), Request::$requestTypesPost, false) === true) {

            $contents = file_get_contents('php://input');

            // Append any PHP-input json
            if (strpos(trim($contents), '{') === 0) {
                $post = json_decode($contents, true);

                if ($post !== false) {
                    $this->originalPost += $post;
                }
            }
        }

        if (\count($this->originalPost) !== 0) {
            $this->post = $this->parseInputItem($this->originalPost);
        }

        /* Parse get requests */
        if (\count($_FILES) !== 0) {
            $this->originalFile = $_FILES;
            $this->file = $this->parseFiles();
        }
    }

    /**
     * @return array
     */
    public function parseFiles(): array
    {
        $list = [];

        foreach ($_FILES as $key => $value) {

            // Handle array input
            if (\is_array($value['name']) === false) {
                $values['index'] = $key;
                try {
                    $list[$key] = InputFile::createFromArray($values + $value);
                } catch (InvalidArgumentException $e) {

                }
                continue;
            }

            $keys = [$key];
            $files = $this->rearrangeFile($value['name'], $keys, $value);

            if (isset($list[$key]) === true) {
                $list[$key][] = $files;
            } else {
                $list[$key] = $files;
            }

        }

        return $list;
    }

    /**
     * Rearrange multi-dimensional file object created by PHP.
     *
     * @param array $values
     * @param array $index
     * @param array|null $original
     * @return array
     */
    protected function rearrangeFile(array $values, &$index, $original): array
    {
        $originalIndex = $index[0];
        array_shift($index);

        $output = [];

        foreach ($values as $key => $value) {

            if (\is_array($original['name'][$key]) === false) {

                try {

                    $file = InputFile::createFromArray([
                        'index'    => (empty($key) === true && empty($originalIndex) === false) ? $originalIndex : $key,
                        'name'     => $original['name'][$key],
                        'error'    => $original['error'][$key],
                        'tmp_name' => $original['tmp_name'][$key],
                        'type'     => $original['type'][$key],
                        'size'     => $original['size'][$key],
                    ]);

                    if (isset($output[$key]) === true) {
                        $output[$key][] = $file;
                        continue;
                    }

                    $output[$key] = $file;
                    continue;

                } catch (InvalidArgumentException $e) {

                }
            }

            $index[] = $key;

            $files = $this->rearrangeFile($value, $index, $original);

            if (isset($output[$key]) === true) {
                $output[$key][] = $files;
            } else {
                $output[$key] = $files;
            }

        }

        return $output;
    }

    /**
     * Parse input item from array
     *
     * @param array $array
     * @return array
     */
    protected function parseInputItem(array $array): array
    {
        $list = [];

        foreach ($array as $key => $value) {

            // Handle array input
            if (\is_array($value) === true) {
                $value = $this->parseInputItem($value);
            }

            $list[$key] = new InputItem($key, $value);
        }

        return $list;
    }

    /**
     * Find input object
     *
     * @param string $index
     * @param array ...$methods
     * @return IInputItem|array|null
     */
    public function find(string $index, ...$methods)
    {
        $element = null;

        if (\count($methods) === 0 || \in_array(Request::REQUEST_TYPE_GET, $methods, true) === true) {
            $element = $this->get($index);
        }

        if (($element === null && \count($methods) === 0) || (\count($methods) !== 0 && \in_array(Request::REQUEST_TYPE_POST, $methods, true) === true)) {
            $element = $this->post($index);
        }

        if (($element === null && \count($methods) === 0) || (\count($methods) !== 0 && \in_array('file', $methods, true) === true)) {
            $element = $this->file($index);
        }

        return $element;
    }

    /**
     * Get input element value matching index
     *
     * @param string $index
     * @param string|mixed|null $defaultValue
     * @param array ...$methods
     * @return string|array
     */
    public function value(string $index, $defaultValue = null, ...$methods)
    {
        $input = $this->find($index, ...$methods);

        /* Handle collection */
        if (\is_array($input) === true) {
            $output = [];
            /* @var $item InputItem */
            foreach ($input as $item) {
                $output[] = \is_array($item) ? $item : $item->getValue();
            }

            return (\count($output) === 0) ? $defaultValue : $output;
        }

        return ($input === null || (\is_string($input->getValue()) && trim($input->getValue()) === '')) ? $defaultValue : $input->getValue();
    }

    /**
     * Check if a input-item exist
     *
     * @param string $index
     * @param array ...$methods
     * @return bool
     */
    public function exists(string $index, ...$methods): bool
    {
        return $this->value($index, null, ...$methods) !== null;
    }

    /**
     * Find post-value by index or return default value.
     *
     * @param string $index
     * @param string|null $defaultValue
     * @return InputItem|array|string|null
     */
    public function post(string $index, ?string $defaultValue = null)
    {
        return $this->post[$index] ?? $defaultValue;
    }

    /**
     * Find file by index or return default value.
     *
     * @param string $index
     * @param string|null $defaultValue
     * @return InputFile|array|string|null
     */
    public function file(string $index, ?string $defaultValue = null)
    {
        return $this->file[$index] ?? $defaultValue;
    }

    /**
     * Find parameter/query-string by index or return default value.
     *
     * @param string $index
     * @param string|null $defaultValue
     * @return InputItem|array|string|null
     */
    public function get(string $index, ?string $defaultValue = null)
    {
        return $this->get[$index] ?? $defaultValue;
    }

    /**
     * Get all get/post items
     * @param array $filter Only take items in filter
     * @return array
     */
    public function all(array $filter = []): array
    {
        $output = $this->originalParams + $this->originalPost + $this->originalFile;
        $output = (\count($filter) > 0) ? \array_intersect_key($output, \array_flip($filter)) : $output;

        foreach ($filter as $filterKey) {
            if (array_key_exists($filterKey, $output) === false) {
                $output[$filterKey] = null;
            }
        }

        return $output;
    }

    /**
     * Add GET parameter
     *
     * @param string $key
     * @param InputItem $item
     */
    public function addGet(string $key, InputItem $item): void
    {
        $this->get[$key] = $item;
    }

    /**
     * Add POST parameter
     *
     * @param string $key
     * @param InputItem $item
     */
    public function addPost(string $key, InputItem $item): void
    {
        $this->post[$key] = $item;
    }

    /**
     * Add FILE parameter
     *
     * @param string $key
     * @param InputFile $item
     */
    public function addFile(string $key, InputFile $item): void
    {
        $this->file[$key] = $item;
    }

    /**
     * Get original post variables
     * @return array
     */
    public function getOriginalPost(): array
    {
        return $this->originalPost;
    }

    /**
     * Set original post variables
     * @param array $post
     * @return static $this
     */
    public function setOriginalPost(array $post): self
    {
        $this->originalPost = $post;
        return $this;
    }

    /**
     * Get original get variables
     * @return array
     */
    public function getOriginalParams(): array
    {
        return $this->originalParams;
    }

    /**
     * Set original get-variables
     * @param array $params
     * @return static $this
     */
    public function setOriginalParams(array $params): self
    {
        $this->originalParams = $params;
        return $this;
    }

    /**
     * Get original file variables
     * @return array
     */
    public function getOriginalFile(): array
    {
        return $this->originalFile;
    }

    /**
     * Set original file posts variables
     * @param array $file
     * @return static $this
     */
    public function setOriginalFile(array $file): self
    {
        $this->originalFile = $file;
        return $this;
    }

}