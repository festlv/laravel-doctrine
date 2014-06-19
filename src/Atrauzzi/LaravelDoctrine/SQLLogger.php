<?php namespace Atrauzzi\LaravelDoctrine;

use Doctrine\DBAL\Logging\SQLLogger as ISQLLogger;

class SQLLogger implements ISQLLogger {
    /**
     * Logs a SQL statement somewhere.
     *
     * @param string     $sql    The SQL to be executed.
     * @param array|null $params The SQL parameters.
     * @param array|null $types  The SQL parameter types.
     *
     * @return void
     */

    protected $start_time;
    protected $query;
    protected $params;

    public function startQuery($sql, array $params = null, array $types = null) {
        $this->start_time = microtime(true);
        $this->query = $sql;
        $this->params = $params;

    }

    /**
     * Marks the last started query as stopped. This can be used for timing of queries.
     *
     * @return void
     */
    public function stopQuery() {
        $col = \Debugbar::getCollector('queries');
        $delta_time = (microtime(true) - $this->start_time) * 1000;
        $col->addQuery($this->query, $this->params, $delta_time, \DB::connection()); 
        
    }


}
