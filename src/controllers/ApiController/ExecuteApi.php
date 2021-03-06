<?php

namespace crocodicstudio\crudbooster\controllers\ApiController;

use crocodicstudio\crudbooster\helpers\DbInspector;
use crocodicstudio\crudbooster\Modules\ModuleGenerator\ControllerGenerator\FieldDetector;

class ExecuteApi
{
    private $ctrl;

    /**
     * ExecuteApi constructor.
     *
     * @param $ctrl
     */
    public function __construct($ctrl)
    {
        $this->ctrl = $ctrl;
    }

    public function execute()
    {
        $result = [];
        $posts = request()->all();
        //$posts_keys = array_keys($posts);
        //$posts_values = array_values($posts);

        $row_api = DB::table('cms_apicustom')->where('permalink', $this->ctrl->permalink)->first();

        $action_type = $row_api->aksi;
        $table = $row_api->tabel;

        $debugModeMessage = 'You are in debug mode !';

        /* Do some custome pre-checking for posted data, if failed discard API execution */
        $this->doCustomePrecheck($posts, $result, $debugModeMessage);

        /* Method Type validation */
        $this->validateMethodType($row_api, $result, $debugModeMessage, $posts);

        /* Check the row is exists or not */
        $this->checkApiDefined($row_api, $result, $debugModeMessage, $posts);

        @$parameters = unserialize($row_api->parameters);
        @$responses = unserialize($row_api->responses);

        /*
        | ----------------------------------------------
        | User Data Validation
        | ----------------------------------------------
        |
        */
        if ($parameters) {
            $type_except = ['password', 'ref', 'base64_file', 'custom', 'search'];
            $input_validator = [];
            $data_validation = [];

            foreach ($parameters as $param) {
                $name = $param['name'];
                $value = $posts[$name];
                $used = $param['used'];

                if ($used == 0) {
                    continue;
                }
                if ($param['config'] && substr($param['config'], 0, 1) != '*') {
                    continue;
                }

                $input_validator[$name] = $value;
                $data_validation[$name] = app(ValidationRules::class)->make($param, $type_except, $table);
            }

            $result = $this->doValidation($input_validator, $data_validation, $result, $debugModeMessage, $posts);
        }

        $responses_fields = $this->prepareResponses($responses);

        $this->ctrl->hookBefore($posts);

        $limit = ($posts['limit']) ?: 20;
        $offset = ($posts['offset']) ?: 0;
        $orderby = ($posts['orderby']) ?: $table.'.id,desc';

        unset($posts['limit'], $posts['offset'], $posts['orderby']);

        if (in_array($action_type, ['list', 'detail', 'delete'])) {
            $data = DB::table($table);
            $data->skip($offset);
            $data->take($limit);
            $data = $this->responses($table, $data, $responses, $responses_fields); //End Responses

            $this->params($parameters, $posts, $data, $table);

            if (\Schema::hasColumn($table, 'deleted_at')) {
                $data->where($table.'.deleted_at', null);
            }

            $this->filterRows($data, $parameters, $posts, $table, $type_except);

            //IF SQL WHERE IS NOT NULL
            if ($row_api->sql_where) {
                $data->whereraw($row_api->sql_where);
            }

            $this->ctrl->hookQuery($data);
            if ($action_type == 'list') {
                list($result, $row) = $this->handleListAction($table, $orderby, $data, $result, $debugModeMessage, $row, $responses_fields);
            }
            $result = $this->handleDetailsAction($action_type, $result, $debugModeMessage, $data, $parameters, $posts, $responses_fields);
            if ($action_type == 'delete') {
                $result = $this->handleDeleteAction($action_type, $table, $data, $result, $debugModeMessage);
            }
        }

        if (in_array($action_type, ['save_add', 'save_edit'])) {
            $this->handleAddEdit($parameters, $posts, $row_assign);
        }

        $this->show($result, $debugModeMessage, $posts);
    }

    /**
     * @param $result
     * @param $debugModeMessage
     * @param $posts
     * @return mixed
     */
    private function show($result, $debugModeMessage, $posts)
    {
        $result['api_status'] = $this->ctrl->hook_api_status ?: $result['api_status'];
        $result['api_message'] = $this->ctrl->hook_api_message ?: $result['api_message'];

        if (cbGetsetting('api_debug_mode') == 'true') {
            $result['api_authorization'] = $debugModeMessage;
        }

        $this->ctrl->hookAfter($posts, $result);

        sendAndTerminate(response()->json($result));
    }

    /**
     * @param $responses
     * @return array
     */
    private function prepareResponses($responses)
    {
        $responsesFields = [];
        foreach ($responses as $r) {
            if ($r['used']) {
                $responsesFields[] = $r['name'];
            }
        }

        return $responsesFields;
    }

    /**
     * @param $table
     * @param $orderby
     * @param $data
     * @param $result
     * @param $debugModeMessage
     * @param $row
     * @param $responses_fields
     * @return array
     */
    private function handleListAction($table, $orderby, $data, $result, $debugModeMessage, $row, $responses_fields)
    {
        $orderby_col = $table.'.id';
        $orderby_val = 'desc';

        if ($orderby) {
            $orderby_raw = explode(',', $orderby);
            $orderby_col = $orderby_raw[0];
            $orderby_val = $orderby_raw[1];
        }

        $rows = $data->orderby($orderby_col, $orderby_val)->get();

        $result['api_status'] = 0;
        $result['api_message'] = 'There is no data found !';
        if (cbGetsetting('api_debug_mode') == 'true') {
            $result['api_authorization'] = $debugModeMessage;
        }
        $result['data'] = [];
        if ($rows) {
            list($row, $result) = $this->handleRows($result, $debugModeMessage, $row, $responses_fields, $rows);
        }

        return [$result, $row];
    }

    /**
     * @param $table
     * @param $data
     * @param $result
     * @param $debugModeMessage
     * @return mixed
     */
    private function handleDeleteAction($table, $data, $result, $debugModeMessage)
    {
        if (\Schema::hasColumn($table, 'deleted_at')) {
            $delete = $data->update(['deleted_at' => date('Y-m-d H:i:s')]);
        } else {
            $delete = $data->delete();
        }

        $result['api_status'] = ($delete) ? 1 : 0;
        $result['api_message'] = ($delete) ? "success" : "failed";
        if (cbGetsetting('api_debug_mode') == 'true') {
            $result['api_authorization'] = $debugModeMessage;
        }

        return $result;
    }

    /**
     * @param $data
     * @param $parameters
     * @param $posts
     * @param $table
     * @param $type_except
     */
    private function filterRows($data, $parameters, $posts, $table, $type_except)
    {
        $data->where(function ($w) use ($parameters, $posts, $table, $type_except) {
            foreach ($parameters as $param) {
                $name = $param['name'];
                $type = $param['type'];
                $value = $posts[$name];
                $used = $param['used'];
                $required = $param['required'];

                if (in_array($type, $type_except)) {
                    continue;
                }

                if ($param['config'] != '' && substr($param['config'], 0, 1) != '*') {
                    $value = $param['config'];
                }

                if ($required == '1') {
                    $this->applyWhere($w, $table, $name, $value);
                } else {
                    if ($used && $value) {
                        $this->applyWhere($w, $table, $name, $value);
                    }
                }
            }
        });
    }

    /**
     * @param $w
     * @param $table
     * @param $name
     * @param $value
     */
    private function applyWhere($w, $table, $name, $value)
    {
        if (\Schema::hasColumn($table, $name)) {
            $w->where($table.'.'.$name, $value);
        } else {
            $w->having($name, '=', $value);
        }
    }

    /**
     * @param $parameters
     * @param $posts
     * @param $data
     * @param $table
     * @return null
     */
    private function params($parameters, $posts, $data, $table)
    {
        foreach ($parameters as $param) {
            $name = $param['name'];
            $type = $param['type'];
            $value = $posts[$name];
            $used = $param['used'];
            $required = $param['required'];
            $config = $param['config'];

            if ($type == 'password') {
                $data->addselect($table.'.'.$name);
            }

            if ($type !== 'search') {
                continue;
            }
            $search_in = explode(',', $config);

            if ($required == '1' || ($used && $value)) {
                $this->applyLike($data, $search_in, $value);
            }
        }
    }

    /**
     * @param $result
     * @param $debug_mode_message
     * @param $row
     * @param $responses_fields
     * @param $rows
     * @return array
     */
    private function handleRows($result, $debug_mode_message, $row, $responses_fields, $rows)
    {
        $uploadsFormatCandidate = explode(',', cbConfig("UPLOAD_TYPES"));
        foreach ($rows as &$row) {
            foreach ($row as $k => $v) {
                $ext = \File::extension($v);
                if (in_array($ext, $uploadsFormatCandidate)) {
                    $row->$k = asset($v);
                }

                if (! in_array($k, $responses_fields)) {
                    unset($row[$k]);
                }
            }
        }

        $result['api_status'] = 1;
        $result['api_message'] = 'success';
        if (cbGetsetting('api_debug_mode') == 'true') {
            $result['api_authorization'] = $debug_mode_message;
        }
        $result['data'] = $rows;

        return [$row, $result];
    }

    /**
     * @param $parameters
     * @param $posts
     * @param $row_assign
     */
    private function handleAddEdit($parameters, $posts, $row_assign)
    {
        foreach ($parameters as $param) {
            $name = $param['name'];
            $used = $param['used'];
            $value = $posts[$name];
            if ($used == '1' && $value == '') {
                unset($row_assign[$name]);
            }
        }
    }

    /**
     * @param $result
     * @param $debug_mode_message
     * @param $posts
     * @return mixed
     */
    private function passwordError($result, $debug_mode_message, $posts)
    {
        $result['api_status'] = 0;
        $result['api_message'] = 'Your password is wrong !';
        if (cbGetsetting('api_debug_mode') == 'true') {
            $result['api_authorization'] = $debug_mode_message;
        }

        $this->show($result, $debug_mode_message, $posts);
    }

    /**
     * @param $rows
     * @param $responses_fields
     * @param $row
     */
    private function handleFile($rows, $responses_fields, $row)
    {
        foreach ($rows as $k => $v) {
            $ext = \File::extension($v);
            if (FieldDetector::isUploadField($ext)) {
                $rows->$k = asset($v);
            }

            if (! in_array($k, $responses_fields)) {
                unset($row[$k]);
            }
        }
    }

    /**
     * @param $table
     * @param $data
     * @param $responses
     * @param $responses_fields
     * @return array
     */
    private function responses($table, $data, $responses, $responses_fields)
    {
        $name_tmp = [];
        foreach ($responses as $resp) {
            $name = $resp['name'];
            $type = $resp['type'];
            $subquery = $resp['subquery'];
            $used = intval($resp['used']);

            if ($used == 0 && ! DbInspector::isForeignKey($name)) {
                continue;
            }

            if (in_array($name, $name_tmp)) {
                continue;
            }

            if ($name == 'ref_id' || $type == 'custom') {
                continue;
            }

            if ($subquery) {
                $data->addSelect(DB::raw('('.$subquery.') as '.$name));
                $name_tmp[] = $name;
                continue;
            }

            if ($used) {
                $data->addSelect($table.'.'.$name);
            }

            $name_tmp[] = $name;
            $name_tmp = $this->joinRelatedTables($table, $responses_fields, $name, $data, $name_tmp);
        }

        return $data;
    }

    /**
     * @param $result
     * @param $debug_mode_message
     * @param $rows
     * @return array
     */
    private function success($result, $debug_mode_message, $rows)
    {
        $result['api_status'] = 1;
        $result['api_message'] = 'success';
        if (cbGetsetting('api_debug_mode') == 'true') {
            $result['api_authorization'] = $debug_mode_message;
        }
        $rows = (array) $rows;
        $result = array_merge($result, $rows);

        return $result;
    }

    /**
     * @param $data
     * @param $search_in
     * @param $value
     */
    private function applyLike($data, $search_in, $value)
    {
        $data->where(function ($w) use ($search_in, $value) {
            foreach ($search_in as $k => $field) {
                if ($k == 0) {
                    $w->where($field, "like", "%$value%");
                } else {
                    $w->orWhere($field, "like", "%$value%");
                }
            }
        });
    }

    /**
     * @param $table
     * @param $responses_fields
     * @param $name
     * @param $data
     * @param $name_tmp
     * @return array
     */
    private function joinRelatedTables($table, $responses_fields, $name, $data, $name_tmp)
    {
        if (DbInspector::isForeignKey($name)) {
            $jointable = CRUDBooster::getTableForeignKey($name);
            $jointable_field = DbInspector::getTableCols($jointable);

            $data->leftjoin($jointable, $jointable.'.id', '=', $table.'.'.$name);
            foreach ($jointable_field as $jf) {
                $jf_alias = $jointable.'_'.$jf;
                if (in_array($jf_alias, $responses_fields)) {
                    $data->addselect($jointable.'.'.$jf.' as '.$jf_alias);
                    $name_tmp[] = $jf_alias;
                }
            }
        }

        return $name_tmp;
    }

    /**
     * @param $row_api
     * @param $result
     * @param $debugModeMessage
     * @param $posts
     * @return mixed
     */
    private function validateMethodType($row_api, $result, $debugModeMessage, $posts)
    {
        $method_type = $row_api->method_type;
        if (! $method_type || ! Request::isMethod($method_type)) {
            $result['api_status'] = 0;
            $result['api_message'] = "The request method is not allowed !";

            $this->show($result, $debugModeMessage, $posts);
        }

        return $result;
    }

    /**
     * @param $posts
     * @param $result
     * @param $debugModeMessage
     * @return mixed
     */
    private function doCustomePrecheck($posts, $result, $debugModeMessage)
    {
        $this->ctrl->hookValidate($posts);
        if ($this->ctrl->validate) { // hook have to return true
            $result['api_status'] = 0;
            $result['api_message'] = "Failed to execute API !";

            $this->show($result, $debugModeMessage, $posts);
        }

        return $result;
    }

    /**
     * @param $row_api
     * @param $result
     * @param $debugModeMessage
     * @param $posts
     * @return mixed
     */
    private function checkApiDefined($row_api, $result, $debugModeMessage, $posts)
    {
        if (! $row_api) {
            $result['api_status'] = 0;
            $result['api_message'] = 'Sorry this API is no longer available, maybe has changed by admin, or please make sure api url is correct.';

            $this->show($result, $debugModeMessage, $posts);
        }

        return $result;
    }

    /**
     * @param $input_validator
     * @param $data_validation
     * @param $result
     * @param $debugModeMessage
     * @param $posts
     * @return mixed
     */
    private function doValidation($input_validator, $data_validation, $result, $debugModeMessage, $posts)
    {
        $validator = Validator::make($input_validator, $data_validation);
        if ($validator->fails()) {
            $message = $validator->errors()->all();
            $message = implode(', ', $message);
            $result['api_status'] = 0;
            $result['api_message'] = $message;

            $this->show($result, $debugModeMessage, $posts);
        }

        return $result;
    }

    /**
     * @param $action_type
     * @param $result
     * @param $debugModeMessage
     * @param $data
     * @param $parameters
     * @param $posts
     * @param $responses_fields
     * @return array
     */
    private function handleDetailsAction($action_type, $result, $debugModeMessage, $data, $parameters, $posts, $responses_fields)
    {
        if ($action_type == 'detail') {
            $result['api_status'] = 0;
            $result['api_message'] = 'There is no data found !';

            if (cbGetsetting('api_debug_mode') == 'true') {
                $result['api_authorization'] = $debugModeMessage;
            }

            $row = $data->first();

            if ($row) {
                foreach ($parameters as $param) {
                    $name = $param['name'];
                    $type = $param['type'];
                    $value = $posts[$name];
                    $used = $param['used'];
                    $required = $param['required'];

                    if ($param['config'] != '' && substr($param['config'], 0, 1) != '*') {
                        $value = $param['config'];
                    }

                    if ($required && $type == 'password' && ! Hash::check($value, $row->{$name})) {
                        $this->passwordError($result, $debugModeMessage, $posts);
                    }

                    if (! $required && $used && $value && ! Hash::check($value, $row->{$name})) {
                        $this->passwordError($result, $debugModeMessage, $posts);
                    }
                }

                $this->handleFile($row, $responses_fields, $row);

                $result = $this->success($result, $debugModeMessage, $row);
            }
        }

        return $result;
    }
}