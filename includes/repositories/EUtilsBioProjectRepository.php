<?php

class EUtilsBioProjectRepository extends EUtilsRepositoryInterface {

  /**
   * Required attributes when using the create method.
   *
   * @var array
   */
  protected $required_fields = [
    'name',
    'description',
    'attributes',
    'accessions',
  ];

  /**
   * Cache of data per run.
   *
   * @var array
   */
  protected static $cache = [
    'db' => [],
    'accessions' => [],
    'projects',
  ];

  /**
   * Takes data from the EUtilsBioProjectParser and creates the chado records
   * needed including project, accessions and props.
   *
   * @param array $data
   *
   * @return object
   * @throws \Exception
   * @see \EUtilsBioProjectParser::parse() to get the data array needed.
   */
  public function create($data) {
    // Throw an exception if a required field is missing
    $this->validateFields($data);

    // Create the base record
    $description = is_array($data['description']) ? implode("\n",
      $data['description']) : $data['description'];

    $project = $this->createproject([
      'name' => $data['name'],
      'description' => $description,
    ]);

    // Create the accessions
    $this->createAccessions($project, $data['accessions']);

   // $this->createProps($project, $data['attributes']);

    return $project;
  }

  /**
   * Create a project record.
   *
   * @param array $data See chado.project schema
   *
   * @return mixed
   * @throws \Exception
   */
  public function createProject(array $data) {
    // Name is unique so find project.
    $project = $this->getProject($data['name']);

    if (!empty($project)) {
      return $project;
    }

    $id = db_insert('chado.project')->fields([
      'name' => $data['name'] ?? '',
      'description' => $data['description'] ?? '',
    ])->execute();

    if (!$id) {
      throw new Exception('Unable to create chado.project record');
    }

    $project = db_select('chado.project', 't')
      ->fields('t')
      ->condition('project_id', $id)
      ->execute()
      ->fetchObject();

    return static::$cache['projects'][$project->name] = $project;
  }

  /**
   * Get project from db or cache.
   *
   * @param string $name
   *
   * @return null
   */
  public function getProject($name) {
    // If the project is available in our static cache, return it
    if (isset(static::$cache['projects'][$name])) {
      return static::$cache['projects'][$name];
    }

    // Find the project and add it to the cache
    $project = db_select('chado.project', 'p')
      ->fields('p')
      ->condition('name', $name)
      ->execute()
      ->fetchObject();

    if ($project) {
      return static::$cache['projects'][$name] = $project;
    }

    return NULL;
  }

  /**
   * Creates a set of accessions attaches them with the given project.
   *
   * @param object $project The project created by createproject()
   * @param array $accessions
   *
   * @return array
   */
  public function createAccessions($project, array $accessions) {
    $data = [];

    foreach ($accessions as $accession) {
      try {
        $data[] = $this->createAccession($project, $accession);
      } catch (Exception $exception) {
        // For the time being, ignore all exceptions
      }
    }
    return $data;
  }

  /**
   * Creates a new accession record if does not exist and attaches it to
   * the given project.
   *
   * @param object $project
   * @param object $accession
   *
   * @return mixed
   * @throws \Exception
   */
  public function createAccession($project, $accession) {
    if (!isset($accession['db'])) {
      throw new Exception('DB not provided for accession ' . $accession['value']);
    }

    $db = $this->getDB('NCBI ' . $accession['db']);

    if (empty($db)) {
      throw new Exception('Unable to find DB NCBI ' . $accession['db'] . '. Please create DB first.');
    }

    $dbxref = $this->getAccessionByName($accession['value'], $db->db_id);

    if (!empty($dbxref)) {
      $this->linkProjectToAccession($project->project_id,
        $dbxref->dbxref_id);

      return $dbxref;
    }

    if (!empty($db)) {
      $id = db_insert('chado.dbxref')->fields([
        'db_id' => $db->db_id,
        'accession' => $accession['value'],
      ])->execute();

      $this->linkProjectToAccession($project->project_id, $id);

      return static::$cache['accessions'][$accession['value']] = $this->getAccessionByID($id);
    }

    return NULL;
  }

  /**
   * Attach an accession to a project.
   *
   * @param int $project_id
   * @param int $accession_id
   *
   * @return \DatabaseStatementInterface|int
   * @throws \Exception
   */
  public function linkProjectToAccession($project_id, $accession_id) {
    return db_insert('chado.project_dbxref')->fields([
      'biomaterial_id' => $project_id,
      'dbxref_id' => $accession_id,
    ])->execute();
  }

}
