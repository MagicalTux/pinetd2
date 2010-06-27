<?php

namespace Daemon\SSHd;

class Base extends \pinetd\TCP\Base {
	public function _ChildIPC_getPublicKeyAccess(&$daemon, $login, $fp, $peer, $service) {
		return $this->getPublicKeyAccess($login, $fp, $peer, $service);
	}

	public function getPublicKeyAccess($login, $fp, $peer, $service) {
		if (($login == 'magicaltux') && ($fp == 'ssh-dss:b489ad30d3a5e3b26597728c27a1ce1c')) {
			return array(
				'login' => $login,
				'type' => 'ssh-dss',
				'key' => 'AAAAB3NzaC1kc3MAAACBAI/CmwDZNxdk7EmeN7AQSLNgF87GMxX74Ifk8WlLWTE3MrpiI0DAi5KTGjP/d2ST2v9abd0C2l8afSM28+H9K2n+TLooh5YE4RqW9deEwhehL7YlhJqTDJ82oDtjSbKy2O7bU63nym5KNK9528d5x6YvdhDthnwILsGe6pk6PQhlAAAAFQCYX0gzwHE0xguIQCFzE0+DTOj9nQAAAIAnRC9ZgVyJgyU76rdQZyo/xldBqJYKLTz6fvJj5Lzh02frnZGdN2XwwO/jQzyYv1/zygg6Erx7dh0K4Sa5awnZuTIAnLOFbboeSbkKbT22VgKOOhjlgBI1yHDbAMf3b9IJrLLaeQdtehYwe7PK8ZqEIVQBPtBtXmvhezwJzBHqowAAAIBT6UxK8x0gqmZc+NDlikiRkRWKecXmQ1YpKIB0RaJbPOVUKEH+E23BJNLb6AaL5k8A8cT6ivAtMncW8HVca0Y9OJNwQmaC05LkVjTF8y6rX/EPCGsug/Un908qmkevn5DZDdhraQ/hBafGG/Cc3hsX6+y6pwmnLvrWLOeo29oCNA==',
			);
		}
		return false;
	}

	public function _ChildIPC_checkAccess(&$daemon, $login, $pass, $peer, $service) {
		return $this->checkAccess($login, $pass, $peer, $service);
	}

	public function checkAccess($login, $pass, $peer, $service) {
		if ($pass == '') return false; // do not allow empty password
		return array('login' => $login); // always OK
	}
}

