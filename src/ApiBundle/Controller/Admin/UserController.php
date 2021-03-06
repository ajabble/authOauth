<?php

namespace ApiBundle\Controller\Admin;

use ApiBundle\Entity\User;
use ApiBundle\Form\UserType;
use ApiBundle\Form\UserProfileType;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\File\Exception\UploadException;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Controller used to manage user contents in the backend.
 *
 * @Route("/admin/user")
 * @Security("has_role('ROLE_ADMIN')")
 *
 * @author Amarendra Kumar Sinha <aksinha@nerdapplabs.com>
 */
class UserController extends Controller
{
    /**
     * Lists all User entities.
     *
     * @Route("/", name="admin_user_index")
     * @Method("GET")
     */
    public function indexAction()
    {
        $repository = $this->getDoctrine()->getRepository('ApiBundle:User');
        $query = $repository->createQueryBuilder('p')
                              ->where('p.enabled = TRUE')
                              ->getQuery();
        $users = $query->getResult();

        return $this->render('@ApiBundle/Resources/views/admin/user/index.html.twig', ['users' => $users]);
    }

    /**
     * Creates a new User entity.
     *
     * @Route("/new", name="admin_user_new")
     * @Method({"GET", "POST"})
     */
    public function newAction(Request $request)
    {
        $userManager = $this->container->get('fos_user.user_manager');
        $user = $userManager->createUser();

        $form = $this->createForm(UserType::class, $user);

        // Role added in admin area
        $form->add('roles', CollectionType::class, array(
                          'entry_type'   => ChoiceType::class,
                          'entry_options'  => array(
                              'label' => false,
                              'choices'  => array(
                                  'ROLE_ADMIN' => 'ROLE_ADMIN',
                                  'ROLE_USER' => 'ROLE_USER',
                                  'ROLE_API'  => 'ROLE_API',
                                  ),
                          ),
        ));

        $locale = $request->getLocale();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // $file stores the uploaded Image file
            /** @var Symfony\Component\HttpFoundation\File\UploadedFile $file */
            $file = $user->getImage();

            // If a file has been uploaded
            if ( null != $file ) {
                // First validate uploaded image. If errors found, return to same page with flash errors
                $imageErrors = $this->validateImage($file, $locale);
                if (!$imageErrors) {
                    return $this->render('@ApiBundle/Resources/views/admin/user/new.html.twig', [
                        'form' => $form->createView(),
                        'attr' =>  array('enctype' => 'multipart/form-data'),
                    ]);
                }
                // Generate a unique name for the file before saving it
                $fileName = md5(uniqid()).'.'.$file->guessExtension();

                // Move the file to the directory where images are stored
                $file->move($this->getParameter('images_profile_path'), $fileName );

                // Update the 'image' property to store the Image file name
                // instead of its contents
                $user->setImage($fileName);
            }

            $this->setUserData($user, $form);

            $userManager->updateUser($user);

            $this->logMessageAndFlash(200, 'success', 'User successfully created: ', $this->get('translator')->trans('flash.user_created_successfully'), $request->getLocale() );

            return $this->redirectToRoute('admin_user_index');
        }

        return $this->render('@ApiBundle/Resources/views/admin/user/new.html.twig', [
            'form' => $form->createView(),
            'attr' =>  array('enctype' => 'multipart/form-data'),
        ]);
    }

    /**
     * Finds and displays a User entity.
     *
     * @Route("/{id}", name="admin_user_show", requirements={"id": "\d+"})
     * @Method("GET")
     */
    public function showAction(User $user)
    {
        $deleteForm = $this->createDeleteForm($user);

        return $this->render('@ApiBundle/Resources/views/admin/user/show.html.twig', [
            'user' => $user,
            'delete_form' => $deleteForm->createView(),
        ]);
    }

    /**
     * Displays a form to edit an existing User entity.
     *
     * @Route("/edit/{id}", requirements={"id": "\d+"}, name="admin_user_edit")
     * @Method({"GET", "POST"})
     */
    public function editAction(User $user, Request $request)
    {
        $entityManager = $this->getDoctrine()->getManager();

        $currentFilename = $user->getImage();
        if ($user->getImage()) {
          $user->setImage(
              new File($this->getParameter('images_profile_path').'/'.$currentFilename)
          );
        }

        $editForm = $this->createForm(UserProfileType::class, $user);
        $deleteForm = $this->createDeleteForm($user);
        $locale = $request->getLocale();

        // Role added in admin area
        $editForm->add('roles', CollectionType::class, array(
                          'entry_type'   => ChoiceType::class,
                          'entry_options'  => array(
                              'label' => false,
                              'choices'  => array(
                                  'ROLE_ADMIN' => 'ROLE_ADMIN',
                                  'ROLE_USER' => 'ROLE_USER',
                                  'ROLE_API'  => 'ROLE_API',
                                  ),
                          ),
        ));

        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            // $file stores the uploaded Image file
            /** @var Symfony\Component\HttpFoundation\File\UploadedFile $file */
            $file = $user->getImage();

            // If a file has been uploaded
            if ( null != $file ) {
                // First validate uploaded image. If errors found, return to same page with flash errors
                $imageErrors = $this->validateImage($file, $locale);
                if (!$imageErrors) {
                    return $this->render('@ApiBundle/Resources/views/admin/user/edit.html.twig', [
                        'user' => $user,
                        'current_image' => $currentFilename,
                        'edit_form' => $editForm->createView(),
                        'delete_form' => $deleteForm->createView(),
                        'attr' =>  array('enctype' => 'multipart/form-data'),
                    ]);
                }

                // Generate a unique name for the file before saving it
                $fileName = md5(uniqid()).'.'.$file->guessExtension();

                // Move the file to the directory where images are stored
                $file->move($this->getParameter('images_profile_path'), $fileName );

                // Update the 'image' property to store the Image file name
                // instead of its contents
                $user->setImage($fileName);
            } else {
                $user->setImage($currentFilename);
            }

            $this->setUserProfileData($user, $editForm);

            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->flush();

            $this->logMessageAndFlash(200, 'success', 'User successfully updated: ', $this->get('translator')->trans('flash.user_updated_successfully'), $request->getLocale() );

            return $this->redirectToRoute('admin_user_index');
        }

        return $this->render('@ApiBundle/Resources/views/admin/user/edit.html.twig', [
            'user' => $user,
            'current_image' => $currentFilename,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
            'attr' =>  array('enctype' => 'multipart/form-data'),
        ]);
    }

    /**
     * Deletes a User entity.
     *
     * @Route("/delete/{id}", name="admin_user_delete")
     */
    public function deleteAction(Request $request, User $user)
    {
        $adminUser = $this->container->get('security.context')->getToken()->getUser();

        if ($adminUser->getId() == $user->getId() ) {
            // Admin is not allowed to delete his own account
            $this->logMessageAndFlash(200, 'danger', 'Admin is not allowed to delete his own account', $this->get('translator')->trans('flash.admin_deleted_denied1'), $request->getLocale() );
        } else {
            $entityManager = $this->getDoctrine()->getManager();
            $user->setEnabled(false);
            // $user->setUpdatedAt(new \DateTime());
            $entityManager->flush();
            $this->logMessageAndFlash(200, 'success', 'User successfully deleted: ', $this->get('translator')->trans('flash.user_deleted_successfully'), $request->getLocale() );
        }
        return $this->redirectToRoute('admin_user_index');
    }

    /**
     * Creates a form to delete a User entity by id.
     *
     * @param User $user The user object
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm(User $user)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('admin_user_delete', ['id' => $user->getId()]))
            ->setMethod('DELETE')
            ->getForm()
        ;
    }

    private function setUserData(User $user, \Symfony\Component\Form\Form $form)
    {
      $user->setFirstname($form['firstname']->getData());
      $user->setLastname($form['lastname']->getData());
      $user->setDob($form['dob']->getData());
      $user->setEmail($form['email']->getData());
      $user->setUsername($form['username']->getData());
      $user->setPlainPassword($form['plainPassword']->getData());
      $user->setRoles($form['roles']->getData());
      $user->setConfirmationToken(null);
      $user->setEnabled(true);
      $user->setLastLogin(new \DateTime());
    }

    private function setUserProfileData(User $user, \Symfony\Component\Form\Form $form)
    {
      $user->setFirstname($form['firstname']->getData());
      $user->setLastname($form['lastname']->getData());
      $user->setDob($form['dob']->getData());
      $user->setEmail($form['email']->getData());
      $user->setUsername($form['username']->getData());
      $user->setRoles($form['roles']->getData());
    }

    private function validateImage(UploadedFile $file, $locale)
    {
        $imageConstraint = new Assert\Image();

        // all constraint "options" can be set this way
        $imageConstraint->mimeTypes = ["image/jpeg", "image/jpg", "image/gif", "image/png"];
        $imageConstraint->mimeTypesMessage = 'Please upload a valid Image (jpeg/jpg/gif/png only within 1024k size';
        $imageConstraint->maxSize = 1024*1024;
        $imageConstraint->minWidth = 100;
        $imageConstraint->minHeight = 100;
        $imageConstraint->payload['api_error'] = 'api.show_error_image';

        // use the validator to validate the value
        $errors = $this->get('validator')->validate($file, $imageConstraint );

        if (count($errors)) {
            // this is *not* a valid image
            $errorArray = [];
            foreach ($errors as $error) {
                $constraint = $error->getConstraint();
                $errorItem = array(
                                    "error_description" => $error->getPropertyPath().': '.$error->getMessage().' '.$error->getInvalidValue(),
                                    "show_message" => $this->get('translator')->trans($constraint->payload['api_error'], array(), 'messages', $locale)
                                  );
                array_push($errorArray, $errorItem);
                $this->logMessageAndFlash(400, 'warning', $errorItem['error_description'], $this->get('translator')->trans('flash.image_error').' '.$errorItem['error_description'], $locale );
            }
            return false;
        }

        return true;
    }

    private function logMessageAndFlash($code = 200, $type = 'success', $logMsg = '', $flashMsg = '', $locale = 'en')
    {
        $this->logMessage($code, $type, $logMsg);
        $this->addFlash($type, $flashMsg);
    }

    private function logMessage($code = 200, $type='success', $logMsg = '') {
        $logger = $this->get('logger');

        if($type === 'success'){
           $logger->info($code . ' ' . $logMsg);
        } else if($type === 'warning'){
           $logger->warning($code . ' ' . $logMsg);
        }
        else if($type === 'danger'){
           $logger->error($code . ' ' . $logMsg);
        }
    }
}
