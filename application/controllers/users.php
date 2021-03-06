<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Users extends CI_Controller
{

  public function __construct()
  {
    parent::__construct();

    $this->load->model('xbmc/users_model');
    $this->load->library('Erkanaauth');
  }

  function index()
  {
    // Si l'utilisateur n'est pas un administrateur, on sort
    if (!$this->session->userdata('is_admin'))
    {
			// L'utilisateur n'est pas dans l'adminitration
      $this->session->set_userdata(array('in_admin' => FALSE));
			redirect('/', 'refresh');
		}

		// L'utilisateur est dans l'adminitration
		$this->session->set_userdata(array('in_admin' => TRUE));

		$tpl['title'] = $this->lang->line('list_users');

		// On charge la vue qui contient le corps de la page
		$tpl['file'] = 'xbmc/users/index';

		ob_start();
		$this->ajax_get_users();
		$tpl['users_list'] = ob_get_contents();
		ob_end_clean();

		$this->load->view('includes/template', $tpl);
  }

  /**
   * Affiche les informations d'un utilisateur via ajax
   */
  public function ajax_edit()
  {
    // Appel via ajax ?
    if (IS_AJAX)
    {
    // Lecture des informations complètes de l'utilisateur
    $user = $this->users_model->get((int) $this->uri->segments[3]);

    $tpl['title'] = $user->username;
    $tpl['user'] = $user;

    // On charge la page dans le template
    $this->load->view('content/xbmc/users/_edit_form', $tpl);
    }
  }

  function ajax_save()
  {
    // Appel via ajax ?
    if (IS_AJAX)
    {
      $data = array();
      $data['username'] = $this->input->post('username');

      // Uniquement si le mot de passe n'est pas vide
      if ($this->input->post('password') != '')
          $data['password'] = $this->input->post('password');

      // Les cases à cocher non cochées ne sont pas postées
      $data['can_change_infos'] = (array_key_exists('can_change_infos', $this->input->post())) ? '1' : '0';
      $data['can_change_images'] = (array_key_exists('can_change_images', $this->input->post())) ? '1' : '0';
      $data['can_download_video'] = (array_key_exists('can_download_video', $this->input->post())) ? '1' : '0';
      $data['can_download_music'] = (array_key_exists('can_download_music', $this->input->post())) ? '1' : '0';
      $data['is_active'] = (array_key_exists('is_active', $this->input->post())) ? '1' : '0';
      $data['is_admin'] = (array_key_exists('is_admin', $this->input->post())) ? '1' : '0';

      // Mise à jour de l'utilisateur et redirection
      $this->users_model->edit(intval($this->uri->segments[3]), $data);

      $json = array('success' => '1',
                    'message' => $this->lang->line('msg_user_updated')
                   );

      $json = json_encode($json);

      header('Content-type: application/json');
      echo $json;
    }
  }

  function ajax_get_users()
  {
    $per_page = 5;

    // Adresse de base pour la pagination
    $base_url = site_url('users/page');
    $data['title'] = $this->lang->line('list_users');

    // Récupération des utilisateurs
    $data['users'] = $this->users_model->get_all($per_page, intval($this->uri->segment(3)));

    // Total des utilisateurs pour la pagination
    $total = $this->users_model->count_all();

    // Paramètrage de la pagination
    // Le 3ème segment contient le numéro de la page
    $config['base_url'] = $base_url;
    $config['total_rows'] = $total;
    $config['per_page'] = $per_page;
    $config['uri_segment'] = '3';

    $config['first_link'] = $this->lang->line('pagination_first_link');
    $config['prev_link'] = $this->lang->line('pagination_prev_link');
    $config['next_link'] = $this->lang->line('pagination_next_link');
    $config['last_link'] = $this->lang->line('pagination_last_link');
    $config['num_links'] = 5;

    $this->my_pagination->initialize($config);

    // On charge la page dans le template
    $this->load->view('content/xbmc/users/_rows', $data);
  }

  function ajax_add()
  {
    // Appel via ajax ?
    if (IS_AJAX)
    {
      $username = $this->input->post('username');
      $password = $this->input->post('password');

      // Vérifie que l'utilisateur n'existe pas déjà
      if ($this->users_model->check_username($username))
      {
        $json = array('message' => $this->lang->line('msg_new_user_exists'));
      }
      else
      {
        // Préparation des données pour retour
        $user->id = $this->users_model->add($username, $password);
        $user->username = $username;
        $user->can_change_images = FALSE;
        $user->can_change_infos = FALSE;
        $user->can_download_video = FALSE;
        $user->can_download_music = FALSE;
        $user->is_active = TRUE;
        $user->is_admin = FALSE;

        $data['key'] = 1; // Ne sert à rien ici mais pris en compte dans la vue

        $data['value'] = $user;

        $json = array('success' => '1',
                      'user' => $this->load->view('content/xbmc/users/_row', $data, TRUE),
                      'message' => $this->lang->line('msg_new_user_added')
                     );
      }

      $json = json_encode($json);

      header('Content-type: application/json');
      echo $json;
      die();
    }
  }

  function ajax_delete()
  {
    // Si l'utilisateur est un administrateur, on traite sinon on sort
    if ($this->session->userdata('is_admin'))
    {
      // Appel via ajax ?
      if (IS_AJAX)
      {
        $user_id = $this->input->post('user_id');

        // L'utilisateur est-il en train de supprimé son compte ?
        if ($this->session->userdata('user_id') == $user_id)
        {
          $json = array('message' => $this->lang->line('msg_your_account'));
        }
        else
        {
          // On traite la suppression de l'utilisateur
          $this->users_model->delete($user_id);
          $json = array('message' => $this->lang->line('msg_user_deleted'));
        }
      }
      else
        redirect('/', 'refresh');

      $json = json_encode($json);

      header('Content-type: application/json');
      echo $json;
      die();
    }
  }

  /**
   * Connecte un utilisateur identifié et modifie la page selon ses droits
   */
  function login()
  {
    $username = $this->input->post('username');
    $password = $this->input->post('password');

    // Cryptage du mot de passe
    $password = md5(md5($this->config->item('encryption_key')).md5($password));

    // Connexion réussie ?
    if ($this->erkanaauth->try_login(array('username' => $username, 'password' => $password)))
    {
			// Lien vers l'administration vide par défaut
			$extra_link = '';

			// Si l'utilisateur est un administrateur, on affiche un lien vers l'administration
			if ($this->session->userdata('is_admin'))
			{
				$extra_link = '<a href="'.site_url('admin').'">'.$this->lang->line('user_admin').'</a>';
			}

      // On modifiera le menu, le message de bienvenue
      // et le bouton connexion/déconnexion
      $json = array('success' => '1',
                    'update' => array('#main-navigation' => $this->load->view('includes/menu', '', TRUE),
                                      '#user_welcome' => sprintf($this->lang->line('user_welcome'), $this->session->userdata('username')),
                                      '#login_out' => '<a class="logout" href="'.site_url('users/logout').'">'.$this->lang->line('user_logout').'</a>',
                                      '#extra_link' => $extra_link
                                      )
                   );

      // Détermine la page sur laquelle l'utilisateur s'est connecté
      // Retourne des informations en fonctions de ses droits
      $rights = $this->_manage_user_rights();

      $json = array_merge_recursive($json, $rights);
    }
    else
    {
      $json = array('message' => $this->lang->line('user_error_login'));
    }

    $json = json_encode($json);

    header('Content-type: application/json');
    echo $json;
    die();
  }

  /**
   * Déconnecte un utilisateur identifié et recharge la page en cours
   */
  function logout()
  {
    $this->erkanaauth->logout();

    // L'utilisateur retourne à l'accueil
    redirect(base_url(), 'refresh');
  }

  /**
   * Gère les droits d'un utilisateur identifié
   * Prépare un tableau pour lister des fichiers javascript à charger
   * et des éléments à modifier selon la page sur laquelle il se trouve
   *
   * @access private
   * @return array
   */
  private function _manage_user_rights()
  {
    $rights = array();

		$this->load->library('user_agent');

		// Si on n'accède pas à la page en direct (cas le plus courant)
		if ($this->agent->is_referral())
		{
			// On récupère le referer (dont on retire l'adresse du site) décomposé en segments
			$segments = explode('/', str_replace(base_url(), '', $this->agent->referrer()));

			// Selon le contrôleur de la page d'où vient l'utilisateur
			switch ($segments[0])
			{
				case 'tvshows':
					// Chargement des modèles de la base de données 'video'
					$this->load->model('video/actors_model');
					$this->load->model('video/countries_model');
					$this->load->model('video/video_files_model');
					$this->load->model('video/genres_model');
					$this->load->model('video/video_paths_model');
					$this->load->model('xbmc/sources_model');
					$this->load->model('video/studios_model');
					$this->load->model('video/episodes_model');
					$this->load->model('video/tvshows_model');

					$rights = $this->_from_tvshows(intval($segments[1]));
					break;

				case 'movies':
					// Chargement des modèles de la base de données 'video'
					$this->load->model('video/actors_model');
					$this->load->model('video/countries_model');
					$this->load->model('video/video_files_model');
					$this->load->model('video/genres_model');
					$this->load->model('video/video_paths_model');
					$this->load->model('xbmc/sources_model');
					$this->load->model('video/sets_model');
					$this->load->model('video/studios_model');
					$this->load->model('video/movies_model');

					$rights = $this->_from_movies(intval($segments[1]));
					break;
			}
		}

    return $rights;
  }

  /**
   * Gère les droits d'un utilisateur identifié sur la page des séries TV
   * quelque soit l'action en cours (liste des séries ou consultation d'une série)
   *
   * @access private
   * @param integer
   * @return array
   */
  private function _from_tvshows($id)
  {
    $js = array();
    $update = array();

		// L'utilisateur peut changer les infos ?
		// On retournera le nom du fichier javascript normalement chargé
		// ainsi que le bouton permettant une mise à jour de la série TV
		if ($this->session->userdata('can_change_images'))
		{
			$js[] = 'tvshows_infos';
			$update['#actions-bar > div'] = $this->load->view('includes/buttons/refresh', '', TRUE);
		}

		// L'utilisateur peut changer les images ?
		// On retournera le nom du fichier javascript normalement chargé
		// ainsi que les images proposées pour en choisir une
		if ($this->session->userdata('can_change_infos'))
		{
			// Lecture des informations partielles de la série TV
			$tvshows = $this->tvshows_model->get($id, TRUE);

			// La fonction précédente retourne un tableau même d'un seul élément
			$tvshow = $tvshows[0];

			// On charge les miniatures en les créant le cas échéant
			$this->tvshows_model->prepare_to_display($tvshow);

			$js[] = 'images';

			// On ne renverra que les posters ou les bannières selon le cas
			if ($tvshow->poster->type == 'poster')
			{
				$data['posters'] = $tvshow->images->posters;
				$update['#posters-list'] = $this->load->view('includes/_posters', $data, TRUE);
			}
			else
			{
				$data['banners'] = $tvshow->images->banners;
				$update['#banners-list'] = $this->load->view('includes/_banners', $data, TRUE);
			}

			// On renverra également les backdrops
			$data['backdrops'] = $tvshow->images->backdrops;
			$update['#backdrops-list'] = $this->load->view('includes/_backdrops', $data, TRUE);
		}

    // On rassemble le tout dans un tableau et on le retourne
    $rights['js'] = $js;
    $rights['update'] = $update;
    return $rights;
  }

  /**
   * Gère les droits d'un utilisateur identifié sur la page des films
   * quelque soit l'action en cours (liste des films ou consultation d'un film)
   *
   * @access private
   * @param integer
   * @return array
   */
  private function _from_movies($id)
  {
    $js = array();
    $update = array();

		// Lecture des informations partielles du film
		$movies = $this->movies_model->get($id, TRUE);

		// La fonction précédente retourne un tableau même d'un seul élément
		$movie = $movies[0];

		$actions_bar = '';

		// L'utilisateur peut changer les infos ?
		// On retournera le nom du fichier javascript normalement chargé
		// ainsi que le bouton permettant une mise à jour du film
		if ($this->session->userdata('can_change_infos'))
		{
			$js[] = 'movies_infos';
			$actions_bar .= $this->load->view('includes/buttons/refresh', '', TRUE);

			// Film dans une saga ?
			if ($movie->set_id != 0)
					$actions_bar .= $this->load->view('includes/buttons/remove_from_set', '', TRUE);
			else
					$actions_bar .= $this->load->view('includes/buttons/add_to_set', '', TRUE);
		}

		if ($this->session->userdata('can_download_video'))
				$actions_bar .= $this->load->view('includes/buttons/download', '', TRUE);

		if ($actions_bar != '')
				$update['#actions-bar > div'] = $actions_bar;

		// L'utilisateur peut changer les images ?
		// On retournera le nom du fichier javascript normalement chargé
		// ainsi que les images proposées pour en choisir une
		if ($this->session->userdata('can_change_images'))
		{
			// On charge les miniatures en les créant le cas échéant
			$this->movies_model->prepare_to_display($movie);

			$js[] = 'images';

			$data['posters'] = $movie->images->posters;
			$update['#posters-list'] = $this->load->view('includes/_posters', $data, TRUE);

			// On renverra également les backdrops
			$data['backdrops'] = $movie->images->backdrops;
			$update['#backdrops-list'] = $this->load->view('includes/_backdrops', $data, TRUE);
		}

    // On rassemble le tout dans un tableau et on le retourne
    $rights['js'] = $js;
    $rights['update'] = $update;
    return $rights;
  }
}

/* End of file users.php */
/* Location: ./application/controllers/users.php */
